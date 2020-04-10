<?php
namespace PSFS\base;

use Exception;
use InvalidArgumentException;
use PSFS\base\config\Config;
use PSFS\base\dto\JsonResponse;
use PSFS\base\exception\AccessDeniedException;
use PSFS\base\exception\AdminCredentialsException;
use PSFS\base\exception\ConfigException;
use PSFS\base\exception\GeneratorException;
use PSFS\base\exception\RouterException;
use PSFS\base\types\Controller;
use PSFS\base\types\helpers\AnnotationHelper;
use PSFS\base\types\helpers\GeneratorHelper;
use PSFS\base\types\helpers\I18nHelper;
use PSFS\base\types\helpers\Inspector;
use PSFS\base\types\helpers\RouterHelper;
use PSFS\base\types\helpers\SecurityHelper;
use PSFS\base\types\traits\SingletonTrait;
use PSFS\controller\base\Admin;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Class Router
 * @package PSFS
 */
class Router
{
    use SingletonTrait;

    /**
     * @var array
     */
    private $routing = [];
    /**
     * @var array
     */
    private $slugs = [];
    /**
     * @var array
     */
    private $domains = [];
    /**
     * @var Finder $finder
     */
    private $finder;
    /**
     * @var Cache $cache
     */
    private $cache;
    /**
     * @var int
     */
    protected $cacheType = Cache::JSON;

    /**
     * Router constructor.
     * @throws GeneratorException
     * @throws ConfigException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function __construct()
    {
        $this->finder = new Finder();
        $this->cache = Cache::getInstance();
        $this->init();
    }

    /**
     * @throws GeneratorException
     * @throws ConfigException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function init()
    {
        list($this->routing, $this->slugs) = $this->cache->getDataFromFile(CONFIG_DIR . DIRECTORY_SEPARATOR . 'urls.json', $this->cacheType, TRUE);
        $this->domains = $this->cache->getDataFromFile(CONFIG_DIR . DIRECTORY_SEPARATOR . 'domains.json', $this->cacheType, TRUE);
        if (empty($this->routing) || Config::getParam('debug', true)) {
            $this->debugLoad();
        }
        $this->checkExternalModules(false);
        $this->setLoaded();
    }

    /**
     * @throws GeneratorException
     * @throws ConfigException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    private function debugLoad() {
        Logger::log('Begin routes load');
        $this->hydrateRouting();
        $this->simpatize();
        Logger::log('End routes load');
    }

    /**
     * @param Exception|NULL $exception
     * @param bool $isJson
     * @return string
     * @throws RouterException
     * @throws GeneratorException
     */
    public function httpNotFound(Exception $exception = NULL, $isJson = false)
    {
        Inspector::stats('[Router] Throw not found exception', Inspector::SCOPE_DEBUG);
        if (NULL === $exception) {
            Logger::log('Not found page thrown without previous exception', LOG_WARNING);
            $exception = new Exception(t('Page not found'), 404);
        }
        $template = Template::getInstance()->setStatus($exception->getCode());
        if ($isJson || false !== stripos(Request::getInstance()->getServer('CONTENT_TYPE'), 'json')) {
            $response = new JsonResponse(null, false, 0, 0, $exception->getMessage());
            return $template->output(json_encode($response), 'application/json');
        }

        $notFoundRoute = Config::getParam('route.404');
        if(null !== $notFoundRoute) {
            Request::getInstance()->redirect($this->getRoute($notFoundRoute, true));
        } else {
            return $template->render('error.html.twig', array(
                'exception' => $exception,
                'trace' => $exception->getTraceAsString(),
                'error_page' => TRUE,
            ));
        }
    }

    /**
     * @return array
     */
    public function getSlugs()
    {
        return $this->slugs;
    }

    /**
     * @return array
     */
    public function getRoutes() {
        return $this->routing;
    }

    /**
     * Method that extract all routes in the platform
     * @return array
     */
    public function getAllRoutes()
    {
        $routes = [];
        foreach ($this->getRoutes() as $path => $route) {
            if (array_key_exists('slug', $route)) {
                $routes[$route['slug']] = $path;
            }
        }
        return $routes;
    }

    /**
     * @param string|null $route
     *
     * @throws Exception
     * @return string HTML
     */
    public function execute($route)
    {
        Inspector::stats('[Router] Executing the request', Inspector::SCOPE_DEBUG);
        try {
            //Search action and execute
            return $this->searchAction($route);
        } catch (AccessDeniedException $e) {
            Logger::log(t('Solicitamos credenciales de acceso a zona restringida'), LOG_WARNING, ['file' => $e->getFile() . '[' . $e->getLine() . ']']);
            return Admin::staticAdminLogon($route);
        } catch (RouterException $r) {
            Logger::log($r->getMessage(), LOG_WARNING);
        } catch (Exception $e) {
            Logger::log($e->getMessage(), LOG_ERR);
            throw $e;
        }

        throw new RouterException(t('Página no encontrada'), 404);
    }

    /**
     * @param string $route
     * @return mixed
     * @throws AccessDeniedException
     * @throws AdminCredentialsException
     * @throws RouterException
     * @throws Exception
     */
    protected function searchAction($route)
    {
        Inspector::stats('[Router] Searching action to execute: ' . $route, Inspector::SCOPE_DEBUG);
        //Revisamos si tenemos la ruta registrada
        $parts = parse_url($route);
        $path = array_key_exists('path', $parts) ? $parts['path'] : $route;
        $httpRequest = Request::getInstance()->getMethod();
        foreach ($this->routing as $pattern => $action) {
            list($httpMethod, $routePattern) = RouterHelper::extractHttpRoute($pattern);
            $matched = RouterHelper::matchRoutePattern($routePattern, $path);
            if ($matched && ($httpMethod === 'ALL' || $httpRequest === $httpMethod) && RouterHelper::compareSlashes($routePattern, $path)) {
                // Checks restricted access
                SecurityHelper::checkRestrictedAccess($route);
                $get = RouterHelper::extractComponents($route, $routePattern);
                /** @var $class Controller */
                $class = RouterHelper::getClassToCall($action);
                try {
                    if($this->checkRequirements($action, $get)) {
                        return $this->executeCachedRoute($route, $action, $class, $get);
                    } else {
                        throw new RouterException(t('La ruta no es válida'), 400);
                    }
                } catch (Exception $e) {
                    Logger::log($e->getMessage(), LOG_ERR);
                    throw $e;
                }
            }
        }
        throw new RouterException(t('Ruta no encontrada'));
    }

    /**
     * @param array $action
     * @param array $params
     * @return bool
     */
    private function checkRequirements(array $action, $params = []) {
        Inspector::stats('[Router] Checking request requirements', Inspector::SCOPE_DEBUG);
        if(!empty($params) && !empty($action['requirements'])) {
            $checked = 0;
            foreach(array_keys($params) as $key) {
                if(in_array($key, $action['requirements'], true)) {
                    $checked++;
                }
            }
            $valid = count($action['requirements']) === $checked;
        } else {
            $valid = true;
        }
        return $valid;
    }

    /**
     * @return string|null
     */
    private function getExternalModules() {
        $externalModules = Config::getParam('modules.extend', '');
        $externalModules .= ',psfs/auth,psfs/nosql';
        return $externalModules;
    }

    /**
     * @param boolean $hydrateRoute
     */
    private function checkExternalModules($hydrateRoute = true)
    {
        $externalModules = $this->getExternalModules();
        $externalModules = explode(',', $externalModules);
        foreach ($externalModules as $module) {
            if(strlen($module)) {
                $this->loadExternalModule($hydrateRoute, $module);
            }
        }
    }

    /**
     * @throws ConfigException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws GeneratorException
     */
    private function generateRouting()
    {
        $base = SOURCE_DIR;
        $modulesPath = realpath(CORE_DIR);
        $this->routing = $this->inspectDir($base, 'PSFS', array());
        $this->checkExternalModules();
        if (file_exists($modulesPath)) {
            $modules = $this->finder->directories()->in($modulesPath)->depth(0);
            if($modules->hasResults()) {
                foreach ($modules->getIterator() as $modulePath) {
                    $module = $modulePath->getBasename();
                    $this->routing = $this->inspectDir($modulesPath . DIRECTORY_SEPARATOR . $module, $module, $this->routing);
                }
            }
        }
        $this->cache->storeData(CONFIG_DIR . DIRECTORY_SEPARATOR . 'domains.json', $this->domains, Cache::JSON, TRUE);
    }

    /**
     * @throws GeneratorException
     * @throws ConfigException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function hydrateRouting()
    {
        $this->generateRouting();
        $home = Config::getParam('home.action', 'admin');
        if (NULL !== $home || $home !== '') {
            $homeParams = NULL;
            foreach ($this->routing as $pattern => $params) {
                list($method, $route) = RouterHelper::extractHttpRoute($pattern);
                if (preg_match('/' . preg_quote($route, '/') . '$/i', '/' . $home)) {
                    $homeParams = $params;
                }
                unset($method);
            }
            if (NULL !== $homeParams) {
                $this->routing['/'] = $homeParams;
            }
        }
    }

    /**
     * @param string $origen
     * @param string $namespace
     * @param array $routing
     * @return array
     * @throws ReflectionException
     * @throws ConfigException
     * @throws InvalidArgumentException
     */
    private function inspectDir($origen, $namespace = 'PSFS', $routing = [])
    {
        $files = $this->finder->files()->in($origen)->path('/(controller|api)/i')->depth('< 3')->name('*.php');
        if($files->hasResults()) {
            foreach ($files->getIterator() as $file) {
                if($namespace !== 'PSFS' && method_exists($file, 'getRelativePathname')) {
                    $filename = '\\' . str_replace('/', '\\', str_replace($origen, '', $file->getRelativePathname()));
                } else {
                    $filename = str_replace('/', '\\', str_replace($origen, '', $file->getPathname()));
                }
                $routing = $this->addRouting($namespace . str_replace('.php', '', $filename), $routing, $namespace);
            }
        }
        $this->finder = new Finder();

        return $routing;
    }

    /**
     * @param string $namespace
     * @return bool
     */
    public static function exists($namespace)
    {
        return (class_exists($namespace) || interface_exists($namespace) || trait_exists($namespace));
    }

    /**
     * @param string $namespace
     * @param array $routing
     * @param string $module
     * @return array
     * @throws ReflectionException
     */
    private function addRouting($namespace, &$routing, $module = 'PSFS')
    {
        if (self::exists($namespace)) {
            if(I18nHelper::checkI18Class($namespace)) {
                return $routing;
            }
            $reflection = new ReflectionClass($namespace);
            if (false === $reflection->isAbstract() && FALSE === $reflection->isInterface()) {
                $this->extractDomain($reflection);
                $classComments = $reflection->getDocComment();
                $api = AnnotationHelper::extractApi($classComments);
                foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    $route = AnnotationHelper::extractRoute($method->getDocComment());
                    if (null !== $route) {
                        list($route, $info) = RouterHelper::extractRouteInfo($method, str_replace('\\', '', $api), str_replace('\\', '', $module));

                        if (null !== $route && null !== $info) {
                            $info['class'] = $namespace;
                            $routing[$route] = $info;
                        }
                    }
                }
            }
        }

        return $routing;
    }

    /**
     *
     * @param ReflectionClass $class
     *
     * @return Router
     * @throws ConfigException
     */
    protected function extractDomain(ReflectionClass $class)
    {
        //Calculamos los dominios para las plantillas
        if ($class->hasConstant('DOMAIN') && !$class->isAbstract()) {
            if (!$this->domains) {
                $this->domains = [];
            }
            $domain = '@' . $class->getConstant('DOMAIN') . '/';
            if (!array_key_exists($domain, $this->domains)) {
                $this->domains[$domain] = RouterHelper::extractDomainInfo($class, $domain);
            }
        }

        return $this;
    }

    /**
     * @return $this
     * @throws GeneratorException
     */
    public function simpatize()
    {
        $this->generateSlugs();
        GeneratorHelper::createDir(CONFIG_DIR);
        Cache::getInstance()->storeData(CONFIG_DIR . DIRECTORY_SEPARATOR . 'urls.json', array($this->routing, $this->slugs), Cache::JSON, TRUE);

        return $this;
    }

    /**
     * @param string $slug
     * @param boolean $absolute
     * @param array $params
     *
     * @return string|null
     * @throws RouterException
     */
    public function getRoute($slug = '', $absolute = false, array $params = [])
    {
        $baseUrl = $absolute ? Request::getInstance()->getRootUrl() : '';
        if ('' === $slug) {
            return $baseUrl . '/';
        }
        if (!is_array($this->slugs) || !array_key_exists($slug, $this->slugs)) {
            throw new RouterException(t('No existe la ruta especificada'));
        }
        $url = $baseUrl . $this->slugs[$slug];
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $url = str_replace('{' . $key . '}', $value, $url);
            }
        } elseif (!empty($this->routing[$this->slugs[$slug]]['default'])) {
            $url = $baseUrl . $this->routing[$this->slugs[$slug]]['default'];
        }

        return preg_replace('/(GET|POST|PUT|DELETE|ALL|HEAD|PATCH)\#\|\#/', '', $url);
    }

    /**
     * @return array
     */
    public function getDomains()
    {
        return $this->domains ?: [];
    }

    /**
     * @param string $class
     * @param string $method
     */
    private function checkPreActions($class, $method) {
        $preAction = 'pre' . ucfirst($method);
        if(method_exists($class, $preAction)) {
            Inspector::stats('[Router] Pre action invoked', Inspector::SCOPE_DEBUG);
            try {
                if(false === call_user_func_array([$class, $preAction])) {
                    Logger::log(t('Pre action failed'), LOG_ERR, [error_get_last()]);
                    error_clear_last();
                }
            } catch (Exception $e) {
                Logger::log($e->getMessage(), LOG_ERR, [$class, $method]);
            }
        }
    }

    /**
     * @param string $route
     * @param array $action
     * @param string $class
     * @param array $params
     * @return mixed
     * @throws exception\GeneratorException
     * @throws ConfigException
     */
    protected function executeCachedRoute($route, $action, $class, $params = NULL)
    {
        Inspector::stats('[Router] Executing route ' . $route, Inspector::SCOPE_DEBUG);
        $action['params'] = array_merge($action['params'], $params, Request::getInstance()->getQueryParams());
        Security::getInstance()->setSessionKey(Cache::CACHE_SESSION_VAR, $action);
        $cache = Cache::needCache();
        $execute = TRUE;
        $return = null;
        if (FALSE !== $cache && $action['http'] === 'GET' && Config::getParam('debug') === FALSE) {
            list($path, $cacheDataName) = $this->cache->getRequestCacheHash();
            $cachedData = $this->cache->readFromCache('json' . DIRECTORY_SEPARATOR . $path . $cacheDataName, $cache);
            if (NULL !== $cachedData) {
                $headers = $this->cache->readFromCache('json' . DIRECTORY_SEPARATOR . $path . $cacheDataName . '.headers', $cache, null, Cache::JSON);
                Template::getInstance()->renderCache($cachedData, $headers);
                $execute = FALSE;
            }
        }
        if ($execute) {
            Inspector::stats('[Router] Start executing action ' . $route, Inspector::SCOPE_DEBUG);
            $this->checkPreActions($class, $action['method']);
            $return = call_user_func_array([$class, $action['method']], $params);
            if (false === $return) {
                Logger::log(t('An error occurred trying to execute the action'), LOG_ERR, [error_get_last()]);
            }
        }
        return $return;
    }

    /**
     * Parse slugs to create translations
     */
    private function generateSlugs()
    {
        foreach ($this->routing as $key => &$info) {
            $keyParts = explode('#|#', $key);
            $keyParts = array_key_exists(1, $keyParts) ? $keyParts[1] : $keyParts[0];
            $slug = RouterHelper::slugify($keyParts);
            $this->slugs[$slug] = $key;
            $info['slug'] = $slug;
            // TODO add routes to translations JSON
        }
    }

    /**
     * @param boolean $hydrateRoute
     * @param SplFileInfo $modulePath
     * @param string $externalModulePath
     * @throws ReflectionException
     */
    private function loadExternalAutoloader($hydrateRoute, SplFileInfo $modulePath, $externalModulePath)
    {
        $extModule = $modulePath->getBasename();
        $moduleAutoloader = realpath($externalModulePath . DIRECTORY_SEPARATOR . $extModule . DIRECTORY_SEPARATOR . 'autoload.php');
        if(file_exists($moduleAutoloader)) {
            include_once $moduleAutoloader;
            if ($hydrateRoute) {
                $this->routing = $this->inspectDir($externalModulePath . DIRECTORY_SEPARATOR . $extModule, '\\' . $extModule, $this->routing);
            }
        }
    }

    /**
     * @param $hydrateRoute
     * @param $module
     * @return mixed
     */
    private function loadExternalModule($hydrateRoute, $module)
    {
        try {
            $module = preg_replace('/(\\\|\/)/', DIRECTORY_SEPARATOR, $module);
            $externalModulePath = VENDOR_DIR . DIRECTORY_SEPARATOR . $module . DIRECTORY_SEPARATOR . 'src';
            if(file_exists($externalModulePath)) {
                $externalModule = $this->finder->directories()->in($externalModulePath)->depth(0);
                if($externalModule->hasResults()) {
                    foreach ($externalModule->getIterator() as $modulePath) {
                        $this->loadExternalAutoloader($hydrateRoute, $modulePath, $externalModulePath);
                    }
                }
            }
        } catch (Exception $e) {
            Logger::log($e->getMessage(), LOG_WARNING);
        }
    }

}

<?php
namespace PSFS\test\base\type\helper;

use PHPUnit\Framework\TestCase;
use PSFS\base\config\Config;
use PSFS\base\types\helpers\DeployHelper;
use PSFS\base\types\helpers\GeneratorHelper;

class GeneratorHelperTest extends TestCase
{

    /**
     * @throws \PSFS\base\exception\GeneratorException
     */
    public function testStructureFunctions()
    {
        // try to create html folders
        GeneratorHelper::createDir(WEB_DIR);
        GeneratorHelper::createDir(WEB_DIR . DIRECTORY_SEPARATOR . 'css');
        GeneratorHelper::createDir(WEB_DIR . DIRECTORY_SEPARATOR . 'js');
        GeneratorHelper::createDir(WEB_DIR . DIRECTORY_SEPARATOR . 'media');
        GeneratorHelper::createDir(WEB_DIR . DIRECTORY_SEPARATOR . 'font');

        // Checks if exists all the folders
        $this->assertFileExists(WEB_DIR . DIRECTORY_SEPARATOR . 'css', 'css folder not exists');
        $this->assertFileExists(WEB_DIR . DIRECTORY_SEPARATOR . 'js', 'js folder not exists');
        $this->assertFileExists(WEB_DIR . DIRECTORY_SEPARATOR . 'media', 'media folder not exists');
        $this->assertFileExists(WEB_DIR . DIRECTORY_SEPARATOR . 'font', 'font folder not exists');

        GeneratorHelper::clearDocumentRoot();
        // Checks if not exists all the folders
        $this->assertFileNotExists(WEB_DIR . DIRECTORY_SEPARATOR . 'css', 'css folder still exists');
        $this->assertFileNotExists(WEB_DIR . DIRECTORY_SEPARATOR . 'js', 'js folder still exists');
        $this->assertFileNotExists(WEB_DIR . DIRECTORY_SEPARATOR . 'media', 'media folder still exists');
        $this->assertFileNotExists(WEB_DIR . DIRECTORY_SEPARATOR . 'font', 'font folder still exists');
    }

    /**
     * @throws \PSFS\base\exception\GeneratorException
     */
    public function testCreateRootDocument() {
        GeneratorHelper::createRoot();
        // Checks if exists all the folders
        $this->assertFileExists(WEB_DIR . DIRECTORY_SEPARATOR . 'css', 'css folder not exists');
        $this->assertFileExists(WEB_DIR . DIRECTORY_SEPARATOR . 'js', 'js folder not exists');
        $this->assertFileExists(WEB_DIR . DIRECTORY_SEPARATOR . 'media', 'media folder not exists');
        $this->assertFileExists(WEB_DIR . DIRECTORY_SEPARATOR . 'font', 'font folder not exists');
        $this->assertFileExists(BASE_DIR . DIRECTORY_SEPARATOR . 'locale', 'locale folder not exists');

        // Check if base files in the document root exists
        $files = [
            'index.php',
            'browserconfig.xml',
            'crossdomain.xml',
            'humans.txt',
            'robots.txt',
        ];
        foreach($files as $file) {
            $this->assertFileExists(WEB_DIR . DIRECTORY_SEPARATOR . $file, $file . ' not exists in html path');
        }
    }

    /**
     * @throws \Exception
     */
    public function testDeployNewVersion() {
        $version = DeployHelper::updateCacheVar();
        $config = Config::getInstance()->dumpConfig();
        $this->assertEquals($config[DeployHelper::CACHE_VAR_TAG], $version, 'Cache version are not equals');
    }
}
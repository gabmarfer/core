'use strict';
app = app || angular.module(module || 'psfs', []);
/**
 * Message Service
 * @type {*[]}
 */
var messageService = ['$rootScope', '$log', function ($rootScope, $log) {
    var config = {
        debug: true
    };
    return {
        'send': function (message, data) {
            if (config.debug) {
                $log.debug('Event: ' + message);
                if (!angular.isUndefined(data) && null !== data) {
                    $log.debug(data);
                }
            }
            $rootScope.$broadcast(message, data);
        },
        $config: function ($config) {
            if (angular.isObject($config)) {
                angular.forEach($config, function (value, key) {
                    config[key] = value;
                });
            }
        }
    };
}];
app.service('$msgSrv', messageService);
var entitySrv = ['$log', function ($log) {
    var entity, id;

    function getLabelField(item) {
        if (item) {
            if ('__name__' in item) return '__name__';
            if ('label' in item) return 'label';
            if ('Label' in item) return 'Label';
            if ('name' in item) return 'name';
            if ('Name' in item) return 'Name';
            if ('title' in item) return 'title';
            if ('Title' in item) return 'Title';
        }
        return '';
    }

    function getLabel(item) {
        if (item) {
            var label = getLabelField(item);
            if (label in item) {
                return item[label];
            }
        }
        return '';
    }

    function getPkField(fields) {
        var pk = null, sep = '';
        fields = fields || {};
        for (var i in fields) {
            var field = fields[i];
            if (field.pk) {
                if (null === pk) pk = '';
                pk += sep + field.name;
                sep = '__|__';
            }
        }
        return pk;
    }

    function getId(item, fields) {
        var pk = getPkField(fields);
        if (null !== pk) {
            if (item[pk]) {
                return item[pk];
            } else if ('__pk' in item) {
                return item['__pk'];
            } else if (null !== pk.match(/__\|__/)) {
                var pks = pk.split('__|__'), complexPk = '', sep = '';
                for (var i in pks) {
                    var _pk = pks[i];
                    if (item[_pk]) {
                        complexPk += sep + item[_pk];
                        sep = '__|__';
                    } else {
                        throw new Error('Unidentified element');
                    }
                }
                return complexPk;
            } else {
                throw new Error('Unidentified element');
            }
        } else if ('__pk' in item) {
            return item['__pk'];
        } else {
            throw new Error('Null object!!!');
        }
    }

    function setEntity(entity) {
        this.entity = entity;
    }

    function setId(id) {
        this.id = id;
    }

    return {
        getLabel: getLabel,
        getLabelField: getLabelField,
        getId: getId,
        setEntity: setEntity,
        getPkField: getPkField,
        setId: setId
    };
}];
app.service('$apiSrv', entitySrv);
/**
 * Message Service
 * @type {*[]}
 */
var httpService = ['$rootScope', '$log', '$http', '$msgSrv', '$httpParamSerializer',
    function ($rootScope, $log, $http, $msgSrv, $httpParamSerializer) {
        var locale = document.getElementsByTagName('HTML')[0].getAttribute('lang') || 'es_ES';
        try {
            locale = defaultLocale || locale;
        } catch (e) {
            // defaultLocale variable does not exists
        }
        var srvConfig = {
            psfsToken: null,
            psfsTokenUrl: null,
            userToken: null,
            debug: true,
            lang: locale,
            useQueryLang: document.getElementsByTagName('HTML')[0].getAttribute('data-query-header') || false
        };

        /**
         *
         * @param $method string
         * @param $url string
         * @param $data object
         * @returns object
         * @private
         */
        function __prepare($method, $url, $data) {
            var config = {
                method: $method,
                url: $url,
                headers: {
                    'Access-Control-Allow-Origin': '*',
                    'Access-Control-Allow-Methods': 'GET,POST,PUT,DELETE,OPTIONS',
                    'Access-Control-Allow-Headers': '*',
                    'Content-Type': 'application/json',
                    'X-API-SEC-TOKEN': srvConfig.psfsToken,
                    'X-API-LANG': srvConfig.lang
                }
            }, __basic_auth = __basic_auth || null;
            if (srvConfig.userToken) {
                config.headers['Authorization'] = 'Bearer ' + srvConfig.userToken;
            }
            if (null !== __basic_auth && !config.headers['Authorization']) {
                config.headers['Authorization'] = 'Basic ' + __basic_auth;
            }
            if (!angular.isUndefined($data) && angular.isObject($data)) {
                if ($method === 'GET') {
                    config.params = $data;
                } else {
                    config.data = $data;
                }
            }
            if ('headers' in srvConfig) {
                angular.merge(config['headers'], srvConfig['headers']);
            }
            return config;
        }

        /**
         * @param $promise $http
         * @param $method string
         * @param $url string
         * @returns {*}
         * @private
         */
        function __return($promise, $method, $url) {
            return $promise.finally(function () {
                if (srvConfig.debug) {
                    $log.debug($url + ' request finished');
                }
                $msgSrv.send('request.' + $method.toLowerCase() + '.finished');
                $msgSrv.send('request.finished');
                return true;
            });
        }

        /**
         * @param $method string
         * @param $url string
         * @param $data object
         * @returns promise
         * @private
         */
        function __call($method, $url, $data) {
            if (false !== srvConfig.useQueryLang) {
                if ($method === 'GET') {
                    $data = $data || {};
                    $data.h_x_api_lang = srvConfig.lang
                } else {
                    if ($url.match(/\?/)) {
                        $url += '&';
                    } else {
                        $url += '?';
                    }
                    $url += 'h_x_api_lang=' + srvConfig.lang;
                }
            }

            var config = __prepare($method, $url, $data);

            $msgSrv.$config({
                debug: srvConfig.debug
            });

            if (srvConfig.debug) {
                $log.debug($url + ' request started');
            }
            $msgSrv.send('request.started');
            $msgSrv.send('request.' + $method.toLowerCase() + '.started');

            return __return($http(config), $method, $url);
        }

        function __upload($url, $data) {
            var config = __prepare('POST', $url, $data);
            config.headers['Content-Type'] = undefined;
            config.transformRequest = angular.identity;

            $msgSrv.$config({
                debug: srvConfig.debug
            });

            if (srvConfig.debug) {
                $log.debug($url + ' request started');
            }
            $msgSrv.send('request.started');
            $msgSrv.send('request.upload.started');
            return __return($http(config), 'upload', $url);
        }

        /**
         * @param $method
         * @param $url
         * @param $data
         * @param contentType
         * @returns {*}
         * @private
         */
        function __download($method, $url, $data, contentType) {
            var config = __prepare($method, $url, $data);
            config.headers['Content-Type'] = contentType || 'blob';
            config.headers['Accept'] = 'blob';
            config.headers['Access-Control-Expose-Headers'] = 'Filename';
            config.responseType = "blob";
            config.transformRequest = angular.identity;
            config.transformResponse = angular.identity;

            switch (contentType) {
                case 'application/json':
                    config.data = JSON.stringify(config.data);
                    break;
                case 'text/html':
                    config.data = $httpParamSerializer(config.data);
                    break;
            }

            $msgSrv.$config({
                debug: srvConfig.debug
            });

            if (srvConfig.debug) {
                $log.debug($url + ' request started');
            }
            $msgSrv.send('request.started');
            $msgSrv.send('request.download.started');

            return __return($http(config)
                .then(function (response) {
                    var headers = response.headers(),
                        fileName = headers['fileName'] || 'noname';
                    if ('noname' === fileName && 'filename' in headers) {
                        fileName = headers['filename'];
                    }
                    if ('noname' === fileName && 'content-disposition' in headers) {
                        fileName = headers['content-disposition'].split(/filename\=/ig).slice(-1).pop().replace(/(\"|\')/ig, '');
                    }
                    if ('noname' === fileName) {
                        var cType = headers['content-type'].split('/').slice(-1).pop();
                        fileName += '.' + cType;
                    }
                    var anchor = window.document.createElement("a");
                    var blob = new Blob([response.data], {type: headers['content-type']});
                    anchor.href = window.URL.createObjectURL(blob);
                    anchor.download = fileName;
                    document.body.appendChild(anchor);
                    anchor.click();
                    return response;
                }), 'download', $url)
                .catch(function (error) {
                    $log.error(error);
                    return error;
                });
        }
        return {
            $get: function (url, query) {
                return __call('GET', url, query);
            },
            $post: function (url, data) {
                return __call('POST', url, data);
            },
            $put: function (url, data) {
                return __call('PUT', url, data);
            },
            $delete: function (url) {
                return __call('DELETE', url, null);
            },
            $upload: function (url, data) {
                return __upload(url, data);
            },
            $download: function (method, url, queryData, contentType) {
                method = method || 'GET';
                var promise;
                switch (method.toUpperCase()) {
                    default:
                    case 'GET':
                        promise = __download('GET', url, queryData, contentType);
                        break;
                    case 'POST':
                        promise = __download('POST', url, queryData, contentType);
                        break;
                }
                return promise;
            },
            $config: function ($config) {
                if (angular.isObject($config)) {
                    angular.forEach($config, function (value, key) {
                        if (key in srvConfig && (angular.isArray(srvConfig[key]) || angular.isObject(srvConfig[key]))) {
                            angular.merge(srvConfig[key], value);
                        } else {
                            srvConfig[key] = value;
                        }
                    });
                }
            },
            $exportConfig: () => {
                return srvConfig;
            }
        };
    }];
app.service('$httpSrv', httpService);
/**
 * Message Service
 * @type {*[]}
 */
var workerDispatcherService = ['$rootScope', '$log', '$msgSrv',
    function ($rootScope, $log, $msgSrv) {
    var workers = {};

    /**
     * Create MessageChannel for a web worker and register it on dispatcher
     * @param name string
     */
    function setup(name, imports) {
        let worker = workers[name];
        worker.ww.postMessage(['setup', {imports: imports}], [worker.mc.port1]);
        worker.mc.port2.onmessage = function(event) {
            let message = Array.isArray(event.data) ? event.data[0] : 'test',
                data = Array.isArray(event.data) && event.data.length > 1 ? event.data[1] : event.data;
            if(worker['ready']) {
                $msgSrv.send('worker.' + name + '.' + message, data);
            }
        };
        ping(name, worker);
        $rootScope.$on('worker.' + name + '.action', (event, data) => {
            worker.ww.postMessage(data);
        });
    }

    function ping(name, worker) {
        $msgSrv.send('worker.' + name + '.ping');
        worker.ww.postMessage(['ping']);
    }

    function activate(name, worker) {
        worker['ready'] = true;
        $msgSrv.send('worker.' + name + '.activated');
    }

    return {
        subscribe: (name, workerSrc, imports) => {
            workers[name] = {
                ww: new Worker(workerSrc),
                mc: new MessageChannel(),
                ready: true
            };
            $msgSrv.send('worker.subscribe.' + name);
            setup(name, imports);
        },
        unsubscribe: (name) => {
            if(name in workers) {
                workers[name].mc.port2.onmessage = null;
                workers[name].ww.postMessage(['unsubscribe']);
                workers[name].ww.terminate();
                delete workers[name];
                $msgSrv.send('worker.unsubscribe.' + name);
            }
        },
        pause: (name) => {
            if(name in workers) {
                $msgSrv.send('worker.' + name + '.paused');
                workers[name]['ready'] = false;
            }
        },
        activate: (name) => {
            if(name in workers) {
                if(!workers[name]['ready']) {
                    activate(name, workers[name]);
                }
            }
        },
        test: () => {
            for(let i in workers) {
                ping(i, workers[i]);
            }
        }
    };
}];
app.service('$workerSrv', workerDispatcherService);
/**
 * Bootstrap core services
 */
var $httpSrv, $msgSrv, $workerSrv;

function loadCallService() {
    try {
        $httpSrv = angular.element(document.body).injector().get('$httpSrv');
        $msgSrv = angular.element(document.body).injector().get('$msgSrv');
        $workerSrv = angular.element(document.body).injector().get('$workerSrv');
    } catch (err) {
        setTimeout(loadCallService, 100);
    }
}

loadCallService();
<?php
App::import('Model', 'Utility.EventTrigger');
App::import('Vendor', 'Hash', array('file' => 'cake/Hash.php'));
/**
 * The EventTrigger bundle is intended to serve as a method for implementing user-defined
 * functionality.
 *
 * To use this, you will need to add "Utility.EventTrigger" to a model's $actsAs property. Refer to
 * the documentation for the addTrigger method below.
 * 
 * @package common
 * @subpackage behaviors
 * @uses EventTrigger
 * @uses Hash
 * @author Tyler Ellis <tyler.ellis@voicemediagroup.com>
 */
class EventTriggerBehavior extends ModelBehavior {
    
    /**
     * @var array Stores a list of behavior configurations passed from the Model it is attached to.
     */
    private $_modelConfigs = array();
    
    /**
     * @var array The default settings to be merged with model configs.
     * 
     * searchBacktrace - boolean - Determines whether callbacks should be searched for in the
     * backtrace (callstack) if an object is not found in the ClassRegistry. This is slow,
     * dangerous, and obviously not recommended.
     *
     * fields - array - A list of fields for use with the EventTrigger helper. This should be
     * formatted in the same way that you would configure Cake's Form::input helper method. These
     * fields will define what the possible conditions are for building a trigger when using the
     * EventTrigger helper to generate form fields.
     *
     * methods - array - A list of methods that are considered valid, whitelisted callbacks for
     * EventTrigger criteria passes. If a method is not listed here, the callback will never fire.
     * This array may be formatted two different ways: as a list of strings containing valid method
     * names, OR as an array with method names as a keys, containing a sub-array with a "fields"
     * array and "label" string. The latter is used in generating form inputs with the EventTrigger
     * helper.
     *
     * boundTargets - array - A list of secondary EventTriggers to listen to. Should be an array
     * containing "target_model", "target_column", and "target_id". Be careful with this: binding
     * different model targets together if both of them are listening may provide awkward event
     * consumption.
     *
     * listeners - null|string|array - Either a list of additional fields to fetch EventTrigger
     * records for, or a string that reads 'all' to listen to every possible column. Be careful with
     * this.
     *
     * lockCallbacks - boolean - Determines whether this behavior should be frozen during callback
     * execution to avoid recursion. If you think you need to disable this, you're probably doing it
     * wrong.
     */
    public $_defaultConfig = array(
        'searchBacktrace' => false,
        'fields' => array(),
        'methods' => array(),
        'boundTargets' => array(),
        'listeners' => null,
        'lockCallbacks' => true,
    );
    
    /**
     * @var Model A reference to the EventTrigger model.
     */
    private $EventTrigger = null;
    
    /**
     * @var Controller A reference to the controller.
     */
    private $Controller = null;
    
    /** 
     * @var array A list of EventTrigger IDs by the associated model and whether they have passed or
     * not during beforeSave.
     */
    private $_prePass = array();
    
    /**
     * This method is called for each model that the behavior is attached to. The passed
     * configuration (if provided) is merged with the default settings and then stored in the
     * $_modelConfigs property. An EventTrigger model is also loaded and attached to the behavior.
     *
     * @param Model $model The model object that the behavior is attached to.
     * @param array $config The optional settings that determine how this behavior "behaves" with
     *    the model.
     */
    function setup(&$model, $config = array()) {
        $this->_modelConfigs[$model->alias] = array_merge_recursive($this->_defaultConfig, $config);
        
        if ($this->EventTrigger === null) {
            $this->EventTrigger =& new EventTrigger();
        }
        
        if ($this->Controller === null) {
            $this->Controller =& ClassRegistry::getObject('controller');
        }
        
        if (!empty($this->Controller->data['_eventTrigger'])) {
            $eventTriggers = $this->Controller->data['_eventTrigger'];
            if (!empty($eventTriggers) && is_string($eventTriggers)) {
                $eventTriggers = array($eventTriggers);
            }
            if (is_array($eventTriggers)) {
                foreach ($eventTriggers as $idx => $eventTrigger) {
                    if (!empty($eventTrigger) && is_string($eventTrigger)) {
                        parse_str($eventTrigger, $eventTrigger);
                    }
                    $eventTriggers[$idx] = $eventTrigger['EventTrigger'];
                }
            }
            $eventTriggers = array('EventTrigger' => $eventTriggers);
            $this->Controller->data = array_merge($this->Controller->data, $eventTriggers);
        }
    }
    
    /**
     * Translates criteria into valid Cake conditions.
     *
     * @usedby _pass()
     * @param Model $model The model that the event trigger belongs to.
     * @param array $criteria The "criteria" parameter from an event trigger.
     * @return array The modified criteria.
     */
    function _cleanCriteria(&$model, $criteria) {
        if (is_array($criteria)) {
            foreach ($criteria as $key => $val) {
                if (!is_numeric($key) && is_string($val)
                && preg_match('/^(\w+)\.(\w+)$/', $val, $m)) {
                    list($alias, $column) = explode('.', $val);
                    $hasField = false;
                    if ($alias === $model->alias && $model->hasField($column, true)) {
                        $hasField = $model->hasField($column, true);
                    } else {
                        $hasField = in_array($alias, array_keys($model->getAssociated()));
                        $hasField = $hasField && $model->$alias->hasField($column, true);
                    }
                    if ($hasField){
                        $criteria[] = preg_match('/\<|\>|\=|\!$/', $key) ? "$key $val" : "$key = $val";
                        unset($criteria[$key]);
                    }
                }
                if (is_array($val) && preg_match('/^(.+)\!\=$/', $key, $m)) {
                    $criteria[$m[1] .' NOT'] = $val;
                    unset($criteria[$key]);
                }
            }
        }
        return $criteria;
    }
    
    /**
     * Tests a trigger's criteria against its target, and returns true if it passes.
     *
     * @param Model $model The model object of the record to test with.
     * @param array $options The event trigger options.
     * @return boolean True if the criteria matches, false if it does not.
     */
    function _pass(&$model, $options) {
        $criteria = $this->_cleanCriteria($model, $options['criteria']);
        $conditions = array(
            'conditions' => array_merge(
                $criteria,
                array($model->alias . '.' . $model->primaryKey => $model->id)
            ),
            'callbacks' => false,
        );
        $pass = $model->find('first', $conditions);
        return !!$pass;
    }
    
    /**
     * This method does a quick pass on existing EventTriggers and stores the result of their
     * passage in our _prePass property. This is used for afterSave to verify if the criteria
     * provided has changed in a way that would simulate a "trigger" as we'd expect.
     * 
     * @param Model $model The model to get triggers for.
     */
    function beforeSave(&$model) {
        $eventTriggers = $this->getTriggers($model, array(
            'conditions' => array(
                'interval' => 0
            )
        ));
        
        foreach (Set::extract('/EventTrigger/.', $eventTriggers) as $eventTrigger) {
            $pass = $this->_pass($model, $eventTrigger);
            $this->_prePass[$model->alias][$eventTrigger['id']] = $pass;
        }
    }
    
    /**
     * This method searches for EventTrigger records that match a pre-determined set of criteria
     * formatted as Cake's model::find() conditions, and attempts to execute the given callback.
     *
     * @param Model $model The model object that this method is triggered from.
     * @param boolean $created Set to true if this was triggered after creating a new record.
     */
    function afterSave(&$model, $created) {
        
        $config = $this->_config($model);
        
        $eventTriggers = $this->getTriggers($model, array(
            'conditions' => array(
                'interval' => 0
            )
        ));
        
        foreach (Set::extract('/EventTrigger/.', $eventTriggers) as $eventTrigger) {
            $pre = false;
            if (isset($this->_prePass[$model->alias][$eventTrigger['id']])) {
                $pre = $this->_prePass[$model->alias][$eventTrigger['id']];
            }
            
            $pass = $this->_pass($model, $eventTrigger);
                
            $this->_log(
                'Trigger pass [$id] $target_model.$target_column=$target_id: '
                . 'pre=' . var_export($pre, true) . ' / post=' . var_export($pass, true),
                $eventTrigger
            );
            
            $pass = $pass && !$pre;
            
            if ($pass === true && $eventTrigger['callback_method']) {
                $args = $eventTrigger['callback_arguments'];
                $success = $this->_callback($model, $eventTrigger['callback_method'], $args);
                
                if ($success === true) {
                    $this->EventTrigger->save(array_merge($eventTrigger, array(
                        'iterations' => ++$eventTrigger['iterations'],
                        'last_triggered_by' => $this->_getUser(),
                    )));
                }
                
            }
            
            unset($this->_prePass[$model->alias][$eventTrigger['id']]);
        }
        
        return true;
    }
    
    /**
     * Returns a model's data. Convenience method.
     *
     * @param Model $model The model to fetch data from.
     * @return null|array A Cake record or null, depending on if a read failed.
     */
    function _getData(&$model) {
        $fields = array_merge(array_keys($model->virtualFields), array_keys($model->schema()));
        $diff = array_diff($fields, array_keys($model->data[$model->alias]));
        if (empty($model->data) || !empty($diff)) {
            $model->read();
        }
        return $model->data;
    }
    
    /**
     * Fetches an set of EventTrigger records for the model that this behavior is attached to.
     *
     * @param Model $model The model to fetch records for.
     * @param array A find() result set containing all of the valid EventTrigger records.
     */
    function getTriggers(&$model, $extra = array()) {
        $conditions = array_replace_recursive(array(
            'conditions' => array(
                'disabled'        => 0,
                'app_dir'         => APP_DIR,
                'OR' => array(
                    array(
                        'target_id'       => $model->id,
                        'target_column'   => $model->primaryKey,
                        'target_model'    => $model->alias,
                    ),
                ),
                'AND' => array(
                    'OR' => array(
                        array('recurring' => 1),
                        array('iterations' => 0),
                    ),
                ),
            )
        ), $extra);
        
        $listeners = $this->_config($model, 'listeners');
        if ($listeners !== null) {
            if ($listeners === 'all') {
                $data = $this->_getData($model);
                foreach ($data[$model->alias] as $k => $v) {
                    if ($k === $model->primaryKey) {
                        continue;
                    }
                    $conditions['conditions']['OR'][] = array(
                        'target_model' => $model->alias,
                        'target_column' => $k,
                        'target_id' => $v,
                    );
                }
            } else if (is_array($listeners)) {
                $data = $this->_getData($model);
                foreach ($listeners as $field) {
                    if ($model->hasField($field, true)) {
                        $conditions['conditions']['OR'][] = array(
                            'target_model' => $model->alias,
                            'target_column' => $field,
                            'target_id' => $data[$model->alias][$field],
                        );
                    }
                }
            }
        }
        
        $boundTargets = $this->_config($model, 'boundTargets');
        if (!empty($boundTargets) && is_array($boundTargets)) {
            $data = $this->_getData($model);
            foreach ($boundTargets as $idx => $boundTarget) {
                if (is_string($idx) && is_string($boundTarget)) {
                    $search = '/^' . preg_quote($model->alias, '/') . '\./';
                    if (preg_match($search, $boundTarget)) {
                        extract(array('idx' => $boundTarget, 'boundTarget' => $idx));
                    }
                    if (preg_match($search, $idx)) {
                        list ($alias, $column) = explode('.', $idx, 2);
                        if (isset($data[$alias]) && isset($data[$alias][$column])) {
                            $conditions['conditions']['OR'][] = array(
                                'target_model' => $alias,
                                'target_column' => $column,
                                'target_id' => $data[$alias][$column],
                            );
                        }
                    }
                } else if (is_array($boundTarget)) {
                    $diff = array_diff_key(
                        array_flip(array('target_model', 'target_id')),
                        $boundTarget
                    );
                    if (empty($diff)) {
                        $target = array_intersect_key(
                            $boundTarget,
                            array_flip(array('target_model', 'target_column', 'target_id'))
                        );
                        if (!isset($target['target_column'])
                        && is_object($model->$target['target_model'])) {
                            $target['target_column'] = $model->$target['target_model']->primaryKey;
                        }
                        $conditions['conditions']['OR'][] = $target;
                    }
                }
            }
        }
        
        return $this->EventTrigger->find('all', $conditions);
    }
    
    /**
     * This method adds a new EventTrigger record for the associated model. This is intended to be
     * used on-the-fly. The $options provided must include criteria to match and a callback method
     * to execute. The recurring and callback_arguments parameters are optional. And id or target_id
     * parameter may be optional if the behavior is able to find it itself using Model::getID().
     *
     * Example usage (from a Controller POV):
     * $this->Model->addTrigger(array(
     *     'criteria' => array(
     *         'Model.deleted' => 1,
     *     ),
     *     'callback_method' => 'sendAlertEmail',
     *     'callback_arguments' => array($emailTo, $otherArgument),
     * ));
     *
     * The next time(s) that model record is saved, EventTriggerBehavior::afterSave() will be
     * called, which will check to see if criteria matches. Once the model is saved with "deleted"
     * set to 1, the EventTrigger will fire off the callback
     * Model::sendAlertEmail($emailTo, $otherArgument), and it will disable itself.
     *
     * Alternatively, if you want to use a callback that exists on your controller, you may add the
     * trigger this way:
     * $this->Model->addTrigger(array(
     *     'callback_method' => 'SuperController::ultraMethod',
     *     ...
     * ));
     *
     * However, wherever the model's save() method is being called, you will need to make sure that
     * you have added an object to the ClassRegistry with the key "SuperController". You can achieve
     * this by including the following code somewhere in your controller (BEFORE Model::save() is
     * called):
     * ClassRegistry::addObject('SuperController', $this);
     *
     * You can use Model::testTrigger() to test whether or not a trigger will work or if its
     * criteria passes.
     * 
     * Possible key-value pairs for $options:
     *
     * "criteria" - array - A list of conditions formatted in Cake's find() style. When the
     * associated record is saved, these conditions are checked - and if all conditions pass, the
     * given callback is executed.
     *
     * "callback_method" - string - The name of the model method to execute OR the name of the
     * the object and its method to execute.
     *
     * "callback_arguments" - array - A list of arguments to pass to the callback method.
     *
     * "recurring" - integer - If set to 1, this event trigger will keep firing the callback every
     * time the record is saved and its criteria matches. If set to 0, the event trigger will only
     * fire the callback once and remain disabled. Defaults to 0.
     *
     * "target_id" - integer - The record ID that the EventTrigger should be linked to.
     *
     * "disabled" - integer - Set to 1 to disable the EventTrigger. This will not delete it.
     *
     * @param Model $model The model that the EventTrigger is attached to.
     * @param array $options A list of options that define the EventTrigger record.
     * @return boolean Returns true if the event was successfully attached or false if not.
     */
    function addTrigger(&$model, $options = array()) {
        $options = $this->_validateTrigger($model, $options);
        
        if ($options === false) {
            return false;
        }
        
        if (!isset($options['id'])) {
            $this->EventTrigger->create();
        }
        $success = $this->EventTrigger->save($options);
        $options['id'] = $this->EventTrigger->getID();
        
        if ($success) {
            $this->_log('Created event [$id] $target_model.$target_column=$target_id', $options);
        }
        
        return !empty($success);
    }
    
    /**
     * Validates the trigger options. This will check to make sure all the required parameters are
     * set and the callback is accessible. It will also "sanitize" the $options array a bit, such as
     * unsetting the "iterations" parameter and adding a "target_model".
     *
     * Do not use this to test triggers; instead use the testTrigger method from the model.
     *
     * @param Model $model The model that the EventTrigger is attached to.
     * @param array $options A list of options that define the EventTrigger record.
     * @return boolean|array Returns false if validation failed, or a sanitized copy of $options.
     */
    function _validateTrigger(&$model, $options = array()) {
        if (!is_array($options) || empty($options)) {
            $this->_error('EventTrigger options can not be empty.');
            return false;
        }
        
        if (empty($options['criteria'])) {
            $this->_error('EventTrigger criteria can not be empty.');
            return false;
        }
        
        if (empty($options['callback_method'])) {
            $this->_error('A valid method name must be provided.');
            return false;
        }
        
        if (!isset($options['callback_arguments']) || !is_array($options['callback_arguments'])) {
            $options['callback_arguments'] = array();
        }
        
        unset($options['iterations']);
        
        if (!isset($options['target_id'])) {
            $options['target_id'] = $model->id ? $model->id : $model->getID();
        }
        
        if (!$options['target_id']) {
            $this->_error('Unable to determine a model ID to attach an event to.');
            return false;
        }
        
        if (!in_array($options['callback_method'], $this->_config($model, 'methods'))
        && !in_array($options['callback_method'], array_keys($this->_config($model, 'methods')))) {
            $this->_error('The callback method requested is not whitelisted in the behavior.');
            return false;
        }
        
        if (!isset($options['target_column']) || !$model->hasField($options['target_column'])) {
            $options['target_column'] = $model->primaryKey;
        }
        
        if (!isset($options['app_dir'])) {
            $options['app_dir'] = APP_DIR;
        }
        
        $options = array_merge($options, array(
            'target_model'    => $model->alias,
            'created_by'      => $this->_getUser(),
        ));
        
        return $options;
    }
    
    /**
     * Attempts to execute a callback when an EventTrigger has passed a criteria check. The callback
     * can be provided as a method name that exists on the behavior's model, or it may be prefixed
     * with an object name (e.g. Controller::methodName). If the Object::methodName format is given,
     * it will first look for the object by name in the ClassRegistry. If the searchBacktrace
     * parameter in the model configuration is set to true, it will fallback to searching for the
     * object in the callstack.
     *
     * If $method does not contain "::" class-to-method notation, then the $method is searched for
     * on the associated model.
     * 
     * If $method does contain the "::" class-to-method notation, then first the requested object is
     * searched for in the ClassRegistry and its $method invoked; if the object is not found in the
     * ClassRegistry AND the `searchBacktrace` parameter is set to true in the model config, then
     * an object is searched for in the backtrace with a class name that ends with what was
     * provided, and $method is searched for on the found object.
     *
     * @param Model $model The model object that the EventTrigger is associated with.
     * @param string $method The callback method to attempt to execute.
     * @param array $args An array of arguments to be passed to the executed callback.
     * @return boolean Returns true if a callback was found and invoked, false if none was found.
     */
    function _callback(&$model, $method, $args) {
        $callback = $method;
        
        $methodList = $this->_config($model, 'methods');
        if (!isset($methodList[$method]) && !in_array($method, $methodList)) {
            $this->_error("The callback \"$callback\" is not in {$model->alias}'s whitelist.");
            return false;
        }
        
        $this->_lock($model);
        
        $success = false;
        
        $callObject =& $model;
        
        if (method_exists($model, $method)) {
            $success = true;
        } else if (stristr($method, '::')) {
            list($class, $method) = explode('::', $method);
            
            if (in_array($class, ClassRegistry::keys())) {
                $object =& ClassRegistry::getObject($class);
                if (is_object($object) && method_exists($object, $method)) {
                    $callObject =& $object;
                    $success = true;
                }
            }
            
            if ($this->_config($model, 'searchBacktrace') === true
            && preg_match('/' . $class . '$/i', $class)) {
                $backtrace = array_slice(debug_backtrace(), 3);
                foreach ($backtrace as $stack) {
                    if (isset($stack['class'])
                    && preg_match('/' . $class . '$/i', $stack['class'])) {
                        if (method_exists($stack['object'], $method)
                        && is_callable(array($stack['object'], $method))) {
                            $callObject =& $stack['object'];
                            $success = true;
                        }
                    }
                }
            }
        }
        
        if ($success === true) {
            $this->_log('Fire callback $class::$method($args)', array(
                'class' => get_class($callObject),
                'method' => $method,
                'args' => @implode(', ', $args)
            ));
            call_user_func_array(array($callObject, $method), $args);
        } else {
            $this->_error('Could not invoke callback "' . $callback . '".');
        }
        
        $this->_unlock($model);
        
        return $success;
    }
    
    function _lock(&$model) {
        if ($this->_config($model, 'lockCallbacks') === true) {
            $model->Behaviors->disable('EventTrigger');
        }
        $this->_log('Locked: ' . $model->alias);
    }
    
    function _unlock(&$model) {
        $model->Behaviors->enable('EventTrigger');
        $this->_log('Unlocked: ' . $model->alias);
    }
    
    /**
     * Wrapper for fetching user identifiers to set last_triggered_by and created_by.
     *
     * @return string Either the authenticated user's email address or "cron" if requested by the
     * cron dispatcher.
     */
    function _getUser() {
        static $user = null;
        if ($user === null && !defined('CRON_DISPATCHER')) {
            $user = $this->Controller->Authorization->userInfo('email');
        } else if (defined('CRON_DISPATCHER')) {
            $user = 'cron';
        }
        return $user;
    }
    
    /**
     * Reads or writes from/to the model configurations. Supports nested keys with a "." delimiter.
     *
     * @param Model $model The model to fetch the configuration for.
     * @param string $key The configuration key to target.
     * @param mixed $value The value to set the $key to.
     */
    function _config(&$model, $key = null, $value = null) {
        if (!isset($this->_modelConfigs[$model->alias])) {
            return null;
        }
        
        $config =& $this->_modelConfigs[$model->alias];
        
        if (func_num_args() !== 3) {
            if ($key === null) {
                return $config;
            }
            
            if (strstr($key, '.')) {
                $expanded = Hash::expand($config);
                if (isset($expanded[$key])) {
                    return $expanded[$key];
                }
            }
            if (isset($config[$key])) {
                return $config[$key];
            }
            return null;
        } else {
            $target =& $config;
            while (strpos($key, '.')) {
                list ($a, $key) = explode('.', $key, 2);
                $target =& $target[$a];
            }
            return $target[$key] = $value;
        }
    }
    
    /**
     * A wrapper method for trigger_error and Object::log().
     *
     * @param string $message The error message to log and print.
     */
    function _error($message) {
        $this->_log($message);
        trigger_error(__CLASS__ . ': ' . $message);
    }
    
    /**
     * A method for testing triggers without saving anything. Essentially just a wrapper for
     * _validateTrigger with optional callback execution.
     *
     * @param Model $model The model to test the trigger against.
     * @param array $options A list of options that define the EventTrigger record.
     * @param boolean $callback If true, attempts to invoke the provided callback if validation
     *   passes.
     * @return boolean Whether the trigger failed or not.
     */
    function testTrigger(&$model, $options, $callback = false) {
        $options = $this->_validateTrigger($model, $options);
        if ($options === false) {
            return false;
        }
        
        $pass = $this->_pass($model, $options);
        
        if ($pass === true && $callback === true) {
            return $this->_callback(
                $model,
                $options['callback_method'],
                $options['callback_arguments']
            );
        }
        
        return $pass;
    }
    
    /**
     * Wrapper method for Cake's log(). Centralized log destination.
     *
     * @param string $message The message to log.
     */
    function _log($message, $vars = false) {
        static $logNs = null;
        $user = $this->_getUser();
        if ($logNs === null) {
            $logNs = substr(md5(mt_rand()), -5);
        }
        if (preg_match('/\$\w+/', $message) && is_array($vars)) {
            $message = preg_replace('/\$(\w+)/e', '(string)$vars[\'$1\']', $message);
        }
        $this->log("[$logNs] [$user] $message", 'event-trigger');
    }
    
}

if (!function_exists('array_replace_recursive')) {
    function array_replace_recursive($array, $array1) {
        // handle the arguments, merge one by one
        $args = func_get_args();
        $array = $args[0];
        if (!is_array($array)) {
            return $array;
        }
        for ($i = 1; $i < count($args); $i++) {
            if (is_array($args[$i])) {
                $array = recurse($array, $args[$i]);
            }
        }
        return $array;
    }
}

if (!function_exists('recurse')) {
    function recurse($array, $array1) {
        foreach ($array1 as $key => $value) {
            // create new key in $array, if it is empty or not an array
            if (!isset($array[$key]) || (isset($array[$key]) && !is_array($array[$key]))) {
                $array[$key] = array();
            }
      
            // overwrite the value in the base array
            if (is_array($value)) {
                $value = recurse($array[$key], $value);
            }
            $array[$key] = $value;
        }
        return $array;
    }
}

?>
<?php

class EventTriggerHelper extends AppHelper {

    var $helpers = array('Form');
    
    private $Controller = null;
    private $View = null;
    
    private $_defaultInputOptions = array(
        'id'        => false,
        'class'     => 'eventTrigger-input',
        'div'       => array('class' => 'eventTrigger-wrapper'),
    );
    
    private $_modelSchema = array();
    
    private $_eventRecords = array();
    
    private $_strOps = array(
        ''    => '=',
        '!='  => '≠',
    );
    
    private $_mathOps = array(
        '<'   => '<',
        '>'   => '>'
    );
    
    function __construct() {
        $this->Controller =& ClassRegistry::getObject('controller');
        $this->View =& ClassRegistry::getObject('view');
    }
    
    function _determineFieldType($field) {
        if (is_array($field)) {
            $field = array_combine($field, array_map(array($this, '_determineFieldType'), $field));
            return $field;
        }
        
        if (strstr($field, '.')) {
            list($model, $column) = explode('.', $field);
        } else {
            $model = $this->View->model;
            $column = $field;
        }
        if (!isset($this->_modelSchema[$model])) {
            App::import('Model', $model);
            if (class_exists($model)) {
                $obj = new $model;
                $this->_modelSchema[$model] = $obj->schema();
            }
        }
        
        if (isset($this->_modelSchema[$model]) && isset($this->_modelSchema[$model][$column])) {
            return $this->_modelSchema[$model][$column];
        }
        
        return $field;
    }
    
    function _findRecords($eventTriggers) {
        if (!empty($eventTriggers)) {
            if (Set::check($eventTriggers, 'EventTrigger')) {
                $eventTriggers = Set::extract('/EventTrigger/.', $eventTriggers);
            }
            if (Set::check($eventTriggers, '0.EventTrigger')) {
                $eventTriggers = Set::extract('/EventTrigger/.', $eventTriggers);
            }
            if (!Set::numeric(array_keys($eventTriggers))) {
                $eventTriggers = array($eventTriggers);
            }
            return $this->_eventRecords = $eventTriggers;
        }
        
        $records = array();
        
        if (!empty($this->View->data['EventTrigger'])) {
            $evt = $this->View->data['EventTrigger'];
            if (!Set::numeric(array_keys($evt))) {
                $evt = array($evt);
            }
            $records = $evt;
        }
        
        foreach (array(
            'eventTrigger',
            'eventTriggers',
            'event_trigger',
            'event_triggers'
        ) as $key) {
            $evt = $this->View->getVar($key);
            if (!empty($evt) && is_array($evt) && !is_object($evt)) {
                if (isset($evt['EventTrigger'])) {
                    $evt = $evt['EventTrigger'];
                }
                if (!Set::numeric(array_keys($evt))) {
                    $evt = array($evt);
                }
                $records = $evt;
            }
        }
        
        if (empty($records)) {
            App::import('Model', 'Utility.EventTrigger');
            $eventTrigger = new EventTrigger();
            
            $records = array($eventTrigger->create());
        }
        
        return $this->_eventRecords = $records;
    }
    
    function _getOptions($model) {
        $options = array();
        if ($model === null) {
            $model = $this->View->model;
        }
        if (is_string($model)) {
            App::import('Model', $model);
            if (class_exists($model)) {
                $model = new $model;
            }
        }
        
        if (is_object($model)
        && is_subclass_of($model, 'Model')
        && $model->Behaviors->attached('EventTrigger')) {
            $options = $model->Behaviors->EventTrigger->_config($model);
        } else {
            App::import('Behavior', 'Utility.EventTrigger');
            $behavior = new EventTriggerBehavior;
            $options = $behavior->_defaultConfig;
        }
        
        return $options;
    }
    
    function build($model = null, $eventTriggers = array()) {
        $this->_findRecords($eventTriggers);
        
        $options = $this->_getOptions($model);
        
        $fields = $options['fields'];
        $methods = $options['methods'];
        
        if (empty($fields) || empty($methods)) {
            return false;
        }
        
        $out = '';
        $out .= $this->script();
        $out .= $this->addTriggerButton();
        foreach ($this->_eventRecords as $idx => $eventRecord) {
            $id = @$eventRecord['id'];
            $recurring = @$eventRecord['recurring'];
            $criteria = @$eventRecord['criteria'];
            $callback = @$eventRecord['callback_method'];
            $arguments = @$eventRecord['callback_arguments'];
            $interval = @$eventRecord['interval'];
            
            $sub = $this->triggerId($id);
            $sub .= $this->removeTriggerButton();
            $sub .= $this->type($recurring, $interval);
            $sub .= $this->when($fields, $criteria);
            $sub .= $this->then($methods, $callback, $arguments);
            
            $sub = $this->Form->Html->tag('div', $sub, array(
                'class' => 'event-trigger-form-inputs',
            ));
            $sub .= $this->disabledPane();
            $out .= $this->Form->Html->tag('div', $sub, array(
                'class' => 'event-trigger-form-subset',
                'style' => $id > 0 || $callback ? null : 'display:none'
            ));
        }
        return $this->Form->Html->tag('div', $out, array(
            'class' => 'event-trigger-form-wrapper',
        ));
    }
    
    function disabledPane() {
        $pane = $this->Form->Html->tag('div', '', array('class'=> 'pane'));
        $text = $this->Form->Html->tag('div', 'This event is marked for deletion. Click save to finish.');
        $text .= $this->Form->Html->link('Undo', false, array('class' => 'eventTrigger-undo-delete'));
        $inner = $pane . $this->Form->Html->tag('div', $text, array('class' => 'eventTrigger-disable-text'));
        return $this->Form->Html->tag('div', $inner, array('class' => 'eventTrigger-disabled-pane'));
    }
    
    function removeTriggerButton() {
        $out = $this->Form->Html->link('Remove Event Trigger', false, array(
            'class' => 'eventTrigger-remove-trigger',
        ));
        return $out;
    }
    
    function addTriggerButton() {
        return $this->Form->Html->link('Add Event Trigger', false, array(
            'class' => 'eventTrigger-add-trigger',
        ));
    }
    
    function script() {
        $out = '';
        
        $mainJs = 'common.utility.event_trigger';
        if (!isset($this->Form->Html->__includedScripts[$mainJs])) {
            $this->Form->Html->__includedScripts[$mainJs] = true;
            $out .= sprintf(
                $this->Form->Html->tags['javascriptlink'],
                '/common/js/' . $mainJs . '.js',
                ''
            );
        }
        
        return $out;
    }
    
    function triggerId($id = null) {
        $inputOptions = array_merge($this->_defaultInputOptions,
        array(
            'div' => false,
            'label' => false,
            'type' => 'text',
            'value' => $id,
            'style' => 'display:none',
        ));
        $inputOptions['class'] .= ' eventTrigger-id';
        return $this->Form->input('EventTrigger.id', $inputOptions);
    }
    
    function type($recurring = null, $interval = null) {
        $intervalMode = $this->Form->input('EventTrigger.interval', array_merge(
            $this->_defaultInputOptions,
            array(
                'label'   => false,
                'type'    => 'select',
                'options' => array(
                    '0'             => 'upon saving',
                    (15 * MINUTE)   => 'in 15 min intervals',
                    (DAY)           => 'each day',
                ),
                'value'   => $interval,
            )
        ));
        return $this->Form->input('EventTrigger.recurring', array_merge(
            $this->_defaultInputOptions,
            array(
                'label'   => 'for',
                'type'    => 'select',
                'options' => array(
                    '0'     => 'the first occurrence',
                    '1'     => 'every occurrence',
                ),
                'value'   => $recurring,
                'after'   => $intervalMode,
            )
        ));
    }
    
    function when($options, $criteria = null) {
        $out = '';
        if (!empty($options) && is_array($options)) {
            $whenList = $this->_denormalizeFields($options);
            
            if (!is_array($criteria) || empty($criteria)) {
                $criteria = array(key($whenList) => current($whenList));
            }
            
            $idx = 0;
            foreach ($criteria as $key => $value) {
                $whenVal = $key;
                $splitOps = implode('|', array_keys(array_merge($this->_strOps, $this->_mathOps)));
                $search = '/([^\s]+)\s?(' . $splitOps . ')$/';
                if (is_string($key) && preg_match($search, $key, $m)) {
                    $whenVal = $m[1];
                }
                $inputOptions = array_merge(
                    $this->_defaultInputOptions,
                    array(
                        'label'       => $idx === 0 ? 'when' : 'and',
                        'type'        => 'select',
                        'options'     => $whenList,
                        'after'       => '',
                        'value'       => $whenVal,
                    )
                );
                $inputOptions['class'] .= ' event-trigger-when';
                $inputOptions['after'] .= $this->is($options, $key, $value);
                $out .= $this->Form->input('EventTrigger.when', $inputOptions);
                $idx++;
            }
        }
        return $out;
    }
    
    function is($options, $key = null, $value = null) {
        $out = '';
        $options = $this->_normalizeFields($options);
        foreach ($options as $field => $inputOptions) {
            $op = false;
            $splitOps = implode('|', array_keys(array_merge($this->_strOps, $this->_mathOps)));
            $search = '/(' . preg_quote($field, '/') . ')\s?(' . $splitOps . ')$/';
            if (is_string($key) && preg_match($search, $key, $m)) {
                $key = $m[1];
                $op = $m[2];
            }
            $inputOptions = array_merge($inputOptions, array(
                'data-trigger-when' => $field,
                'label'             => false,
            ));
            if (!empty($inputOptions['hybridOptions'])) {
                $inputOptions['data-hybrid-options'] = json_encode($inputOptions['hybridOptions']);
            }
            if ($field === $key) {
                $inputOptions['value'] = $value;
                $inputOptions['initial-value'] = $value;
                if (!is_array($value) && strtotime($value) === false
                && strstr($inputOptions['class'], 'load-datepicker')
                && !empty($inputOptions['hybridOptions'])) {
                    $inputOptions['disabled'] = true;
                }
            }
            $opOpts = $this->_strOps;
            if (isset($inputOptions['hasOps']) && $inputOptions['hasOps'] === true) {
                $opOpts = array_merge($opOpts, $this->_mathOps);
            }
            $before = $this->Form->input('EventTrigger.criteria.' . $field . '_op', array_merge(
                $this->_defaultInputOptions,
                array(
                    'label' => false,
                    'type' => 'select',
                    'data-trigger-when' => $field,
                    'options' => $opOpts,
                    'value' => $op ? $op : key($opOpts),
                )
            ));
            if (!empty($inputOptions['before'])) {
                $inputOptions['before'] .= $before;
            } else {
                $inputOptions['before'] = $before;
            }
            $inputOptions['class'] .= ' event-trigger-is';
            $out .= $this->Form->input('EventTrigger.criteria.' . $field, $inputOptions);
        }
        return $out;
    }
    
    function then($methods, $callback = null, $arguments = null) {
        if (empty($methods)) {
            return '';
        }
        
        $methodInputOptions = array_merge(
            $this->_defaultInputOptions,
            array(
                'label' => 'then',
                'type' => 'select',
                'options' => array_combine(
                    array_keys($methods),
                    Set::classicExtract($methods, '{s}.label')
                ),
                'value' => $callback,
            )
        );
        $methodInputOptions['class'] .= ' event-trigger-then';
        
        $out = '';
        foreach ($methods as $method => $params) {
            switch ($method) {
                case 'updateField':
                    $fieldsNormalized = $this->_normalizeFields($params['fields']);
                    $fieldsDenormalized = $this->_denormalizeFields($params['fields']);
                    
                    $fieldInputOptions = array_merge(
                        $this->_defaultInputOptions,
                        array(
                            'label' => false,
                            'data-trigger-then' => $method,
                            'options' => $fieldsDenormalized,
                            'after' => ''
                        )
                    );
                    if ($callback === $method && !empty($arguments)) {
                        $fieldInputOptions['value'] = $arguments[0];
                    }
                    $fieldInputOptions['class'] .= ' event-trigger-then-' . $method;
                    
                    foreach ($fieldsNormalized as $field => $inputOptions) {
                        $inputOptions = array_merge($inputOptions, array(
                            'data-trigger-then-' . $method => $field,
                            'label' => 'to',
                        ));
                        if (!empty($inputOptions['hybridOptions'])) {
                            $inputOptions['data-hybrid-options'] = json_encode($inputOptions['hybridOptions']);
                        }
                        if ($callback === $method
                        && !empty($arguments)
                        && $field === $arguments[1]) {
                            $inputOptions['value'] = $arguments[1];
                        }
                        $inputOptions['class'] .= ' event-trigger-then-' . $method . '-value';
                        $fieldInputOptions['after'] .= $this->Form->input(
                            'EventTrigger.callback_arguments.1',
                            $inputOptions
                        );
                    }
                    
                    $out .= $this->Form->input(
                        'EventTrigger.callback_arguments.0',
                        $fieldInputOptions
                    );
                break;
                default:
                    $fieldIdx = 0;
                    foreach ($params['fields'] as $field => $fieldOpts) {
                        $fieldOpts = $this->_cleanInputOpts($fieldOpts);
                        $fieldInputOptions = array_merge(
                            $this->_defaultInputOptions,
                            $fieldOpts,
                            array(
                                'data-trigger-then' => $method,
                            )
                        );
                        if (!empty($fieldOpts['class'])) {
                            $fieldOpts['class'] .= ' ' . $this->_defaultInputOptions['class'];
                        }
                        if ($callback === $method && !empty($arguments)) {
                            $fieldInputOptions['value'] = $arguments[$fieldIdx];
                        }
                        if (!empty($fieldInputOptions['hybridOptions'])) {
                            $fieldInputOptions['data-hybrid-options'] = json_encode($fieldInputOptions['hybridOptions']);
                            $fieldInputOptions['initial-value'] = @$fieldInputOptions['value'];
                        }
                        $fieldInputOptions['class'] .= ' event-trigger-then-' . $method;
                        $out .= $this->Form->input(
                            'EventTrigger.callback_arguments.' . $fieldIdx,
                            $fieldInputOptions
                        );
                        $fieldIdx++;
                    }
                break;
            }
        }
        
        $methodInputOptions['after'] = $out;
        
        return $this->Form->input('EventTrigger.callback_method', $methodInputOptions);
    }
    
    function _cleanInputOpts($params) {
        if (isset($params['options']) &&
        is_string($params['options']) && preg_match('/^\$(\w+)/', $params['options'], $m)) {
            $var = $this->View->getVar($m[1]);
            if (!empty($var)) {
                $params['options'] = $var;
            }
        }
        return $params;
    }
    
    function _inputOptions($definition) {
        $typeOpts = array(
            'type' => 'text',
        );
        
        if ($definition['type'] === 'boolean'
        || ($definition['type'] === 'integer' && $definition['length'] == 1)) {
            $typeOpts = array_merge($typeOpts, array(
                'type'    => 'select',
                'options' => array(
                    '1'   => 'True',
                    '0'   => 'False',
                ),
            ));
        }
        
        if ($definition['type'] === 'date' || $definition['type'] === 'datetime') {
            $typeOpts = array_merge($typeOpts, array(
                'class'   => 'load-datepicker',
            ));
        }
        
        $inputOptions = array_merge(
            $this->_defaultInputOptions,
            $typeOpts
        );
        
        return $inputOptions;
    }
    
    function _normalizeFields($fields) {
        $out = array();
        foreach ($fields as $field => $opts) {
            if (!is_array($opts)) {
                $opts = array_merge(
                    $this->_inputOptions($this->_determineFieldType($field)),
                    array('label' => $opts)
                );
            } else {
                $opts = array_merge($this->_defaultInputOptions, $opts);
            }
            $out[$field] = $opts;
        }
        return $out;
    }
    
    function _denormalizeFields($fields) {
        $out = array();
        foreach ($fields as $field => $opts) {
            if (is_array($opts)) {
                $opts = $opts['label'];
            }
            $out[$field] = $opts;
        }
        return $out;
    }
    
}

<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../../3rdparty/Toml.php';
require_once dirname(__FILE__) . '/snips.hermes.class.php';
require_once dirname(__FILE__) . '/snips.tts.class.php';
require_once dirname(__FILE__) . '/snips.handler.class.php';
require_once dirname(__FILE__) . '/snips.utils.class.php';
// ini_set("display_errors","On");
// error_reporting(E_ALL);

class snips extends eqLogic
{
    const NAME_OBJECT = 'Snips-Intents';

    /* get current assistant language */
    static function get_assistant_language()
    {
        $obj = object::byName(self::NAME_OBJECT);
        if (!$obj) {
            return false;
        }
        return $obj->getConfiguration('language', 'en');
    }

    /* static method, get all the intent eqLogic objects */
    static function dump_eq_intent()
    {
        $eq_intent = array();
        $eqs = self::byType('snips');
        foreach ($eqs as $eq) {
            if ($eq->getConfiguration('Intent')) {
                $eq_intent[] = $eq;
            }
        }
        return $eq_intent;
    }

    /* static method, get all the tts eqLogic objects */
    static function dump_eq_tts()
    {
        $eq_tts = array();
        $eqs = self::byType('snips');
        foreach($eqs as $eq){
            if ($eq->getConfiguration('TTS')) {
                $eq_TTS[] = $eq;
            }
        }
        return $eq_tts;
    }

    /* start to install denpendancy */
    static function dependancy_install()
    {
        log::remove(__CLASS__ . '_dep');
        $resource_path = realpath(dirname(__FILE__) . '/../../resources');
        passthru(
            'sudo /bin/bash '. $resource_path .'/install.sh '.
            $resource_path .' > '. log::getPathToLog('snips_dep') .' 2>&1 &'
        );
        return true;
    }

    /* return dependency status */
    static function dependancy_info()
    {
        $return = array();
        $return['log'] = 'snips_dep';
        $return['progress_file'] = jeedom::getTmpFolder('snips') .'/dependance';
        $return['state'] = 'nok';
        $cmd = "dpkg -l | grep mosquitto";
        exec($cmd, $output, $return_var);
        $libphp = extension_loaded('mosquitto');
        if ($output[0] != "" && $libphp) {
            $return['state'] = 'ok';
        }
        return $return;
    }

    /* return deamon status */
    static function deamon_info()
    {
        $res = array();
        $res['log'] = '';
        $res['state'] = 'nok';
        $cron = cron::byClassAndFunction('snips', 'deamon_hermes');
        if (is_object($cron) && $cron->running()) {
            $res['state'] = 'ok';
        }
        $dependancy_info = self::dependancy_info();
        if ($dependancy_info['state'] == 'ok') {
            $res['launchable'] = 'ok';
        }
        return $res;
    }

    /* start hermes client in a deamon */
    static function deamon_start()
    {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Please check your configuration', __FILE__));
        }
        $cron = cron::byClassAndFunction('snips', 'deamon_hermes');
        if (!is_object($cron)) {
            throw new Exception(__('Can not find task corn ', __FILE__));
        }
        $cron->run();
    }

    /* stop hermes client */
    static function deamon_stop()
    {
        $cron = cron::byClassAndFunction('snips', 'deamon_hermes');
        if (!is_object($cron)) {
            throw new Exception(__('Can not find taks corn', __FILE__));
        }
        $cron->halt();
    }

    /* create hermes client and run */
    static function deamon_hermes()
    {
        $addr = config::byKey('mqttAddr', 'snips', '127.0.0.1');
        $H = new SnipsHermes($addr, 1883);
        $H->subscribe_intents('SnipsHandler::intent_detected');
        $H->subscribe_session_ended('SnipsHandler::session_ended');
        $H->subscribe_session_started('SnipsHandler::session_started');
        $H->subscribe_hotword_detected('SnipsHandler::hotword_detected');
        $H->start();
    }

    /* start a new hermes client for calling publish related APIs */
    static function hermes()
    {
        $addr = config::byKey('mqttAddr', 'snips', '127.0.0.1');
        $H = new SnipsHermes($addr, 1883);
        return $H;
    }

    /* get a list of bindings of this intent */
    function get_bindings()
    {
        return SnipsBinding::dump(
            $this->getConfiguration('bindings'),
            $this->name
        );
    }

    /* get the callback scenario of this intent */
    function get_callback_scenario()
    {
        $raw_array = $this->getConfiguration('callbackScenario');
        $callback_scenario = new SnipsBindingScenario($raw_array);
        if (!is_object($callback_scenario)) {
            SnipsUtils::logger('No callback scenario found');
            return false;
        }
        return $callback_scenario;
    }

    /* Check if this intent is using Snips binding */
    public function is_snips_config()
    {
        $res = $this->getConfiguration('isSnipsConfig', false);
        return ($res === '1') ? true : false;
    }

    /* Check if this intent is using Jeedom interaction */
    public function is_interaction()
    {
        $res = $this->getConfiguration('isInteraction', false);
        return ($res === '1') ? true : false;
    }

    /* after saving the confguration, update snips variable */
    static function postConfiguration()
    {
        SnipsUtils::update_scenario_variable('snipsMsgSession');
        SnipsUtils::update_scenario_variable('snipsMsgSiteId');
        SnipsUtils::update_scenario_variable('snipsMsgHotwordId');
    }

    /* statistic of saved bindings */
    function preSave()
    {
        // only for intent eqobjects
        if ($this->getConfiguration('snipsType') != 'Intent') {
            return;
        }

        $slots = $this->getConfiguration('slots');
        $slot_set = array();
        foreach ($slots as $slot) {
            $slot_set[] = $slot['name'];
        }

        $bindings = $this->getConfiguration('bindings');
        foreach ($bindings as $key => $binding) {
            $necessary_slots = array();
            // check each condition expression
            $conditions = $binding['condition'];
            foreach($conditions as $condition) {
                $cmd_name = cmd::byId($condition['pre'])->getName();
                if (!in_array($cmd_name, $necessary_slots)) {
                    $necessary_slots[] = $cmd_name;
                }
            }
            // check each action expression
            $actions = $binding['action'];
            foreach($actions as $action) {
                $options = $action['options'];
                foreach($options as $option) {
                    if (preg_match("/#.*#/", $option, $match_res)) {
                        $cmd_name = cmd::byId(
                            str_replace('#', '', $match_res[0])
                        )->getName();
                        if (
                            in_array($cmd_name, $slot_set) &&
                            !in_array($cmd_name, $necessary_slots)
                        ) {
                            $necessary_slots[] = $cmd_name;
                        }
                    }
                }
            }
            // save necessary slots into binding
            if (!empty($necessary_slots)) {
                $bindings[$key]['nsr_slots'] = $necessary_slots;
            }
        }
        $this->setConfiguration('bindings', $bindings);
    }

    // function preInsert() {}
    // function postInsert() {}
    // function postSave() {}
    // function preUpdate() {}
    // function postUpdate() {}
    // function preRemove() {}
    // function postRemove() {}
}

class snipsCmd extends cmd
{
    function execute($options = array())
    {
        $eqlogic = $this->getEqLogic();
        switch ($this->getLogicalId()) {
            case 'say':
                $this->snips_say($options);
                break;
            case 'ask':
                $this->snips_ask($options);
                break;
        }
    }

    function snips_say($options = array())
    {
        SnipsUtils::logger('cmd: say, text: '.$options['message']);
        snips::hermes()->publish_start_session_notification(
            $this->getConfiguration('siteId'),
            $options['message']
        );
    }

    function snips_ask()
    {
        SnipsUtils::logger('cmd: ask');
        preg_match_all("/(\[.*?\])/", $options['answer'][0], $match_intent);
        $ans_intent = str_replace('[', '', $match_intent[0][0]);
        $ans_intent = str_replace(']', '', $ans_intent);
        snips::hermes()->publish_start_session_action(
            $this->getConfiguration('siteId'),
            $options['message'],
            null,
            array($ans_intent)
        );
    }
}
<?php
/**
 * this file contains common PHP functions used during the benchmark execution
 * process
 */

// get runtime parameters from environment variables

// set the start time
$bm_start_time = microtime(TRUE);

// API parameters
$bm_api = getenv('bm_param_api');

// API endpoint
$bm_api_endpoint = getenv('bm_param_api_endpoint');

// API key
$bm_api_key = getenv('bm_param_api_key');

// API secret
$bm_api_secret = getenv('bm_param_api_secret');

// debug flag
$bm_debug = getenv('bm_param_debug') && getenv('bm_param_debug') == '1';

// OS
$bm_os = getenv('bm_param_os');

// Quantity
$bm_quantity = getenv('bm_param_quantity');
$bm_quantity = !is_numeric($bm_quantity) || $bm_quantity < 1 || $bm_quantity > 1000 ? 1 : $bm_quantity;

// service region
$bm_region = getenv('bm_param_region');

// timeout
$bm_timeout = getenv('bm_param_timeout');
$bm_timeout = !is_numeric($bm_timeout) || $bm_timeout < 60 || $bm_timeout > 14400 ? 1200 : $bm_timeout;

// type
$bm_type = getenv('bm_param_type');

// verify method
$bm_verify = getenv('bm_param_verify');
$bm_verify = $bm_verify != 'api' && $bm_verify != 'ping' && !preg_match('/^port_[0-9]+$/', $bm_verify) ? 'api' : $bm_verify;

// rounding precision
define('PROVISION_ROUND_PRECISION', 4);

// timeout for verification (ping and port check)
define('VERIFY_TIMEOUT', 15);

/**
 * returns the current execution time
 * @return float
 */
function bm_exec_time() {
	global $bm_start_time;
	return round(microtime(TRUE) - $bm_start_time, PROVISION_ROUND_PRECISION);
}

/**
 * returns a reference to the provisioning controller for the test. returns 
 * NULL if there is an error and the controller cannot be instantiated
 * @return ProvisioningController
 */
function &bm_get_controller() {
  global $bm_api;
	global $bm_controller;
	global $bm_debug;
	if (!$bm_controller) {
	  if ($bm_debug) bm_log_msg(sprintf('Instantiating ProvisioningController for the %s API', $bm_api), basename(__FILE__), __LINE__);
    require_once(dirname(dirname(__FILE__)) . '/apis/ProvisioningController.php');
    if (is_dir($dir = dirname(dirname(__FILE__)) . '/apis/' . $bm_api)) {
      $d = dir($dir);
      $controller_file = NULL;
      while($file = $d->read()) {
        if (preg_match('/ProvisioningController\.php$/', $file)) $controller_file = $file;
      }
      if ($controller_file) {
        require_once($dir . '/' . $controller_file);
        $controller_class = str_replace('.php', '', basename($controller_file));
        if (class_exists($controller_class)) {
          $bm_controller = new ${controller_class}();
          if (is_subclass_of($bm_controller, 'ProvisioningController')) {
            global $bm_api_endpoint;
            $bm_controller->api_endpoint = $bm_api_endpoint;
            global $bm_api_key;
            $bm_controller->api_key = $bm_api_key;
            global $bm_api_secret;
            $bm_controller->api_secret = $bm_api_secret;
            $bm_controller->debug = $bm_debug;
            global $bm_os;
            $bm_controller->os = $bm_os;
            global $bm_quantity;
            $bm_controller->quantity = $bm_quantity;
            global $bm_region;
            $bm_controller->region = $bm_region;
            global $bm_timeout;
            $bm_controller->timeout = $bm_timeout;
            global $bm_type;
            $bm_controller->type = $bm_type;
            global $bm_verify;
            $bm_controller->verify = $bm_verify;
            if ($bm_debug) bm_log_msg(sprintf('ProvisioningController implementation %s for API %s instantiated successfully. Validating...', $controller_class, $bm_api), basename(__FILE__), __LINE__);
            $validate = $bm_controller->validate();
            if ($validate !== TRUE) {
              bm_log_msg(sprintf('Runtime parameters are invalid - aborting test. Validate error: %s', $validate), basename(__FILE__), __LINE__, TRUE);
              $bm_controller = NULL;              
            }
            else if ($bm_debug) bm_log_msg(sprintf('Runtime parameters are valid'), basename(__FILE__), __LINE__);
          }
          else bm_log_msg(sprintf('ProvisioningController implementation %s for API %s does not extend the base class ProvisioningController', $controller_class, $bm_api), basename(__FILE__), __LINE__, TRUE);
        }
      }
      else bm_log_msg(sprintf('ProvisioningController implementation not found for API %s', $bm_api), basename(__FILE__), __LINE__, TRUE);
    }
    else bm_log_msg(sprintf('API %s is invalid', $bm_api), basename(__FILE__), __LINE__, TRUE);
	}
	return $bm_controller;
}

/**
 * returns the arithmetic mean value from an array of points
 * @param array $points an array of numeric data points
 * @param int $round desired rounding precision, default is 2
 * @access public
 * @return float
 */
function bm_get_mean($points, $round=PROVISION_ROUND_PRECISION) {
	$stat = array_sum($points)/count($points);
	if ($round) $stat = round($stat, $round);
	return $stat;
}

/**
 * returns the median value from an array of points
 * @param array $points an array of numeric data points
 * @param int $round desired rounding precision, default is 2
 * @access public
 * @return float
 */
function bm_get_median($points, $round=PROVISION_ROUND_PRECISION) {
	sort($points);
	$nmedians = count($points);
	$nmedians2 = floor($nmedians/2);
  $stat = $nmedians % 2 ? $points[$nmedians2] : ($points[$nmedians2 - 1] + $points[$nmedians2])/2;
	if ($round) $stat = round($stat, $round);
	return $stat;
}

/**
 * prints a log message
 * @param string $msg the message to output
 * @param string $source the source of the message
 * @param int $line an optional line number
 * @param boolean $error is this an error message
 * @param string $source1 secondary source
 * @param int $line1 secondary line number
 * @return void
 */
$bm_error_level = error_reporting();
function bm_log_msg($msg, $source=NULL, $line=NULL, $error=FALSE, $source1=NULL, $line1=NULL) {
	global $bm_error_level;
	$source = basename($source);
	if ($source1) $source1 = basename($source1);
	$exec_time = bm_exec_time();
	// avoid timezone errors
	error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
	$timestamp = date('m/d/Y H:i:s T');
	error_reporting($bm_error_level);
	printf("%-24s %-12s %-12s %s\n", $timestamp, bm_exec_time() . 's', 
				 $source ? str_replace('.php', '', $source) . ($line ? ':' . $line : '') : '', 
				 ($error ? 'ERROR - ' : '') . $msg . 
				 ($source1 ? ' [' . str_replace('.php', '', $source1) . ($line1 ? ":$line1" : '') . ']' : ''));
}

// set default time zone if necessary
if (!ini_get('date.timezone')) ini_set('date.timezone', ($tz = trim(shell_exec('date +%Z'))) ? $tz : 'UTC');
?>
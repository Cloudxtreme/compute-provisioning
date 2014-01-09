<?php
/**
 * Abstract class to be extended by corresponding service APIs. Implements 
 * functionality for initiating compute instance provisioning requests and 
 * tracking the status of those requests. One file should be present in the 
 * API specific directories where the name of that file ends with 
 * 'ProvisioningController.php' (e.g. Tier3ProvisioningController.php). This
 * file should contain a class by the same name (e.g. class 
 * Tier3ProvisioningController) which extends this class
 */
abstract class ProvisioningController {
  /**
   * runtime parameters - set automatically following instantiation
   */
  var $api_endpoint;
  var $api_key;
  var $api_secret;
  var $debug;
  var $quantity;
  var $os;
  var $region;
  var $timeout;
  var $type;
  var $verify;
  
  /**
   * optional function - called upon completion of a test iteration if the 
   * validate method had previously returned TRUE. May be used to cleanup 
   * connections and/or files created during the test process
   * @return void
   */
  function cleanup() {}
  
  /**
   * destroy the compute instance identified by $instanceId (the value returned
   * from the isComplete method). return TRUE on success, FALSE on failure
   * @param string $instanceId the identifier of the compute instance to 
   * destroy. this value was previously returned by the isComplete method
   * @return boolean
   */
  abstract function destroy($instanceId);
  
  /**
   * optional method that may be overridden to return the CPU name for the 
   * instance identified. If implemented, this value will be included in the
   * results
   * @param string $instanceId the identifier of the compute instance to 
   * return the CPU information for
   * @return string
   */
  function getCpu($instanceId) {}
  
  /**
  * return the IP address (or hostname) for the instance specified. This 
  * method is only used for 'ping' and 'port_n' verification. return NULL
  * on failure
   * @param string $instanceId the identifier of the compute instance to 
   * return the IP address for
   * @return string
   */
  abstract function getIp($instanceId);
  
  /**
   * check if provisioning for the compute instance identified by $requestId 
   * is complete. return value should be FALSE if provisioning is still pending
   * or an instance identifier string if complete. if an error has occurred, 
   * the return value should be NULL
   * @param string $requestId the provisioning request identifier (returned by
   * the provision method)
   * @return mixed
   */
  abstract function isComplete($requestId);
    
  /**
   * initiate provisioning for a single compute instance based on the instance 
   * attributes associated with the object. return value should be FALSE on 
   * error, or a request identifier that will be passed to the isComplete 
   * method
   * @return mixed
   */
  abstract function provision();
  
  /**
   * invoked directly following instantiation. Implementations should return 
   * TRUE if runtime parameters are set and valid. If invalid, it should return 
   * an error message string. this method may be used to validate credentials 
   * and compute instance parameters
   */
  abstract function validate();
  
  /**
   * verifies that an instance has been provisioned based on the verification
   * method specified for the test (api, ping or port_n). returns TRUE if 
   * verification is successful, FALSE if it fails, NULL on error
   * @param string $instanceId the ID of the instance to verify
   * @return boolean
   */
  public final function verify($instanceId) {
    global $bm_debug;
    $verified = TRUE;
    if (($ip = $this->verify != 'api' ? $this->getIp($instanceId) : FALSE) === NULL) $verified = NULL;
    // api verification - nothing to do
    if ($this->verify == 'api' && $bm_debug) bm_log_msg(sprintf('No further action needed for API verification. Instance %s verification successful', $instanceId), basename(__FILE__), __LINE__);
    // ping verification
    else if ($this->verify == 'ping') {
      $code = shell_exec(sprintf('ping -c 2 -W %d %s; echo $?', round(VERIFY_TIMEOUT/4), $ip))*1;
      if ($code !== 0) {
        bm_log_msg(sprintf('Unable to ping %s (exit code %d)', $ip, $code), basename(__FILE__), __LINE__, TRUE);
        $verified = FALSE;
      }
      else if ($bm_debug) bm_log_msg(sprintf('Pinged %s successfully. Verification of instance %s complete', $ip, $instanceId), basename(__FILE__), __LINE__);
    }
    // port verification
    else if (preg_match('/^port_([0-9]+)$/', $this->verify, $m)) {
      $port = $m[1]*1;
      if ($fp = fsockopen($ip, $port, $errno, $errstr, VERIFY_TIMEOUT)) {
        if ($bm_debug) bm_log_msg(sprintf('Connected to %s:%d successfully. Verification of instance %s complete', $ip, $port, $instanceId), basename(__FILE__), __LINE__);
        fclose($fp);
      }
      else {
        bm_log_msg(sprintf('Failed to connect to %s:%d due to error no %d and error string %s', $ip, $port, $errno, $errstr), basename(__FILE__), __LINE__, TRUE);
        $verified = FALSE;
      }
    }
    return $verified;
  }
  
}
?>

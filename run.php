#!/usr/bin/php -q
<?php
/**
 * this script performs a test iteration. It utilizes the following exit status 
 * codes
 *   0 Iteration successful
 *   1 Iteration failed
 */
require_once(dirname(__FILE__) . '/lib/util.php');
$status = 0;

if ($controller =& bm_get_controller()) {
  $results = array();
  
  // initiate provisioning requests
  for($i=0; $i<$controller->quantity; $i++) {
    if ($bm_debug) bm_log_msg(sprintf('Initiating provisioning request #%d', $i + 1), basename(__FILE__), __LINE__);
    $results[$i] = array('provision_cpu' => NULL, 'provision_instance_id' => FALSE, 'provision_ip' => NULL, 'provision_request_id' => NULL, 'request_time' => NULL, 'provision_status' => NULL, 'provision_time' => NULL);
    $requestTime = microtime(TRUE);
    if ($requestId = $controller->provision()) {
      $results[$i]['provision_request_id'] = $requestId;
      $results[$i]['request_time'] = $requestTime;
      if ($bm_debug) bm_log_msg(sprintf('Provisioning request successful, requestID: %s; requestTime: %d', $requestId, $requestTime), basename(__FILE__), __LINE__);
    }
    else {
      $results[$i]['provision_status'] = 'failed';
      bm_log_msg(sprintf('Provisioning request failed'), basename(__FILE__), __LINE__, TRUE);
    }
  }
  // poll for provisioning completion
  while(TRUE) {
    foreach(array_keys($results) as $i) {
      // already compute
      if ($results[$i]['provision_status']) continue;
      
      // try to get instanceId
      if ($results[$i]['provision_instance_id'] === FALSE && ($instanceId = $controller->isComplete($requestId)) !== FALSE) $results[$i]['provision_instance_id'] = $instanceId;
      
      // try to verify
      $verified = $results[$i]['provision_instance_id'] ? $controller->verify($results[$i]['provision_instance_id']) : FALSE;
      
      // instance is provisioned and verified
      if ($results[$i]['provision_instance_id'] && $verified) {
        $results[$i]['provision_cpu'] = $controller->getCpu($results[$i]['provision_instance_id']);
        $results[$i]['provision_ip'] = $controller->getIp($results[$i]['provision_instance_id']);
        $results[$i]['provision_status'] = 'success';
        $results[$i]['provision_time'] = round(microtime(TRUE) - $results[$i]['request_time'], PROVISION_ROUND_PRECISION);
        if ($bm_debug) bm_log_msg(sprintf('Provisioning of request %s completed successfully in %f seconds. instanceId=%s; cpu=%s; ip=%s', $results[$i]['provision_request_id'], $results[$i]['provision_time'], $results[$i]['provision_instance_id'], $results[$i]['provision_cpu'], $results[$i]['provision_ip']), basename(__FILE__), __LINE__);
      }
      
      // instance provisioned but verification resulted in an error
      else if ($results[$i]['provision_instance_id'] && $verified === NULL) {
        bm_log_msg(sprintf('Provisioning of request %s (instanceId=%s) successful, but verification failed', $results[$i]['provision_request_id'], $results[$i]['provision_instance_id']), basename(__FILE__), __LINE__, TRUE);
        $results[$i]['provision_status'] = 'failed';
      }
      
      // instance provisioned, but verification did not complete
      else if ($results[$i]['provision_instance_id'] && $bm_debug) bm_log_msg(sprintf('Provisioning of request %s completed (instanceId=%s) but could not be verified yet using %s verification - will continue polling', $results[$i]['provision_request_id'], $results[$i]['provision_instance_id'], $bm_verify), basename(__FILE__), __LINE__);
      
      // provisioning error
      else if ($results[$i]['provision_instance_id'] === NULL) {
        bm_log_msg(sprintf('Provisioning of request %s failed', $results[$i]['provision_request_id']), basename(__FILE__), __LINE__, TRUE);
        $results[$i]['provision_status'] = 'failed';
      }
    }
    
    // check if all requests complete
    $incomplete = 0;
    foreach(array_keys($results) as $i) if (!$results[$i]['provision_status']) $incomplete++;
    
    if (!$incomplete) {
      if ($bm_debug) bm_log_msg(sprintf('All provision requests complete - test complete', $incomplete), basename(__FILE__), __LINE__);
      break;
    }
    if ($bm_debug) bm_log_msg(sprintf('There are still %d incomplete requests - sleeping for 1 second before polling again', $incomplete), basename(__FILE__), __LINE__);
    sleep(1);
    
    // check for timeout condition
    if (bm_exec_time() > $bm_timeout) {
      if ($bm_debug) bm_log_msg(sprintf('Timeout %d reached - flagging pending provision requests as timed out and aborting the test', $bm_timeout), basename(__FILE__), __LINE__, TRUE);
      foreach(array_keys($results) as $i) if (!$results[$i]['provision_status']) $results[$i]['provision_status'] = 'timeout';
    }
  }
  // destroy instances
  foreach(array_keys($results) as $i) {
    if (!$results[$i]['provision_instance_id']) continue;
    $destroyed = $controller->destroy($results[$i]['provision_instance_id']);
    if ($bm_debug && $destroyed) bm_log_msg(sprintf('Request to destroy instance %s successful', $results[$i]['provision_instance_id']), basename(__FILE__), __LINE__);
    else if ($bm_debug && !$destroyed) bm_log_msg(sprintf('Request to destroy instance %s failed', $results[$i]['provision_instance_id']), basename(__FILE__), __LINE__, TRUE);
  }
  // cleaup
  $controller->cleanup();
  
  // print results
	print("\n\n[results]\n");
	foreach(array_keys($results) as $i) {
	  foreach($results[$i] as $key => $val) {
	    if ($key == 'request_time') continue;
	    if ($val) printf("%s%d=%s\n", $key, $i + 1, $val);
	  }
  }
}

exit($status);
?>
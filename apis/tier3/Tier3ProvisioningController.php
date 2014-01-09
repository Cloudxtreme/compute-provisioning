<?php
// cookie file path
define('TIER3_COOKIEJAR', getenv('bm_run_dir') . '/tier3-cookiejar.txt');

// tier 3 default API endpoint
define('TIER3_API_ENDPOINT', 'https://api.tier3.com/REST');

// max CPU value
define('TIER3_MAX_CPU', 16);

// max Memory value
define('TIER3_MAX_MEMORY', 48);

/**
 * ProvisioningController implementation for the Tier 3 API
 */
class Tier3ProvisioningController extends ProvisioningController {
  
  var $aliases = array();
  var $cpu;
  var $group_id;
  var $memory;
  var $network;
  var $pswd;
  var $server_type;
  var $service_level;
  var $validated;
  
  /**
   * optional function - called upon completion of a test iteration if the 
   * validate method had previously returned TRUE. May be used to cleanup 
   * connections and/or files created during the test process
   * @return void
   */
  function cleanup() {
    $this->invoke('/Auth/Logout/');
  }
  
  /**
   * destroy the compute instance identified by $instanceId (the value returned
   * from the isComplete method). return TRUE on success, FALSE on failure
   * @param string $instanceId the identifier of the compute instance to 
   * destroy. this value was previously returned by the isComplete method
   * @return boolean
   */
  function destroy($instanceId) {
    $deleted = FALSE;
    if ($this->validate() === TRUE && $instanceId) {
      $response = $this->invoke('/Server/DeleteServer/JSON', array('Name' => $instanceId, 'LocationAlias' => $this->region));
      if (isset($response['StatusCode']) && $response['StatusCode'] === 0) {
        if ($this->debug) bm_log_msg(sprintf('Successfully initiated deletion of server %s. Request ID %s', $instanceId, $response['RequestID']), basename(__FILE__), __LINE__);
        $deleted = TRUE;
      }
      else {
        bm_log_msg(sprintf('Unable to delete server %s. Status Code: %d; Message: %s', $instanceId, isset($response['StatusCode']) ? $response['StatusCode'] : 'unknown', isset($response['Message']) ? $response['Message'] : 'unknown'), basename(__FILE__), __LINE__, TRUE);
        $complete = NULL;
      }
    }
    return $deleted;
  }
  
  /**
   * optional method that may be overridden to return the CPU name for the 
   * instance identified. If implemented, this value will be included in the
   * results
   * @param string $instanceId the identifier of the compute instance to 
   * return the CPU information for
   * @return string
   */
  function getCpu($instanceId) {
    // TODO
    return NULL;
  }
  
  /**
   * return the IP address (or hostname) for the instance specified. This 
   * method is only used for 'ping' and 'port_n' verification. return NULL
   * on failure
   * @param string $instanceId the identifier of the compute instance to 
   * return the IP address for
   * @return string
   */
  function getIp($instanceId) {
    $ip = NULL;
    if ($this->validate() === TRUE && $instanceId) {
      $response = $this->invoke('/Server/GetServer/JSON', array('Name' => $instanceId, 'LocationAlias' => $this->region));
      if (isset($response['StatusCode']) && $response['StatusCode'] === 0) {
        if ($this->debug) bm_log_msg(sprintf('Successfully queried server information for %s. IP Address: %s', $instanceId, $response['Server']['IPAddress']), basename(__FILE__), __LINE__);
        $ip = $response['Server']['IPAddress'];
      }
      else {
        bm_log_msg(sprintf('Unable to query server information for %s. Status Code: %d; Message: %s', $instanceId, isset($response['StatusCode']) ? $response['StatusCode'] : 'unknown', isset($response['Message']) ? $response['Message'] : 'unknown'), basename(__FILE__), __LINE__, TRUE);
        $complete = NULL;
      }
    }
    return $ip;
  }
  
  /**
   * check if provisioning for the compute instance identified by $requestId 
   * is complete. return value should be FALSE if provisioning is still pending
   * or an instance identifier string if complete. if an error has occurred, 
   * the return value should be NULL
   * @param string $requestId the provisioning request identifier (returned by
   * the provision method)
   * @return mixed
   */
  function isComplete($requestId) {
    $complete = FALSE;
    if ($this->validate() === TRUE && $requestId) {
      $response = $this->invoke('/Blueprint/GetBlueprintStatus/JSON', array('RequestID' => $requestId, 'LocationAlias' => $this->region));
      if (isset($response['StatusCode']) && $response['StatusCode'] === 0) {
        if ($this->debug) bm_log_msg(sprintf('Successfully queried for provisioning status of request %s', $requestId), basename(__FILE__), __LINE__);
        if (isset($response['CurrentStatus']) && $response['CurrentStatus'] == 'Succeeded') {
          bm_log_msg(sprintf('Provisioning of request %s completed in successfully. Server ID is %s', $requestId, $response['Servers'][0]), basename(__FILE__), __LINE__, TRUE);
          $complete = $response['Servers'][0];
        }
        else if (isset($response['CurrentStatus']) && $response['CurrentStatus'] == 'Failed') {
          bm_log_msg(sprintf('Provisioning of request %s completed in a failed state', $requestId), basename(__FILE__), __LINE__, TRUE);
          $complete = NULL;
        }
        else if ($this->debug) bm_log_msg(sprintf('Provisioning of request %s is pending. Current state is %s. PercentComplete is %s; Step is %s', $requestId, $response['CurrentStatus'], $response['PercentComplete'], $response['Step']), basename(__FILE__), __LINE__);
      }
      else {
        bm_log_msg(sprintf('Unable to query provisioning status for request %s. Status Code: %d; Message: %s', $requestId, isset($response['StatusCode']) ? $response['StatusCode'] : 'unknown', isset($response['Message']) ? $response['Message'] : 'unknown'), basename(__FILE__), __LINE__, TRUE);
        $complete = NULL;
      }
    }
    return $complete;
  }
  
  /**
   * initiate provisioning for a single compute instance based on the instance 
   * attributes associated with the object. return value should be FALSE on 
   * error, or a request identifier that will be passed to the isComplete 
   * method
   * @return mixed
   */
  function provision() {
    $provisioned = FALSE;
    if ($this->validate() === TRUE) {
      if (!$this->pswd) $this->pswd = 'AaBb!' . rand();
      $alias = getenv('bm_resource_id') . (count($this->aliases) + 1);
      $this->aliases[] = $alias;
      if ($this->debug) bm_log_msg(sprintf('Initiating provisioning for compute instance using alias %s and password %s', $alias, $this->pswd), basename(__FILE__), __LINE__);
      $params = array('LocationAlias' => $this->region, 
                      'Template' => $this->os, 
                      'Alias' => $alias, 
                      'HardwareGroupID' => $this->group_id,
                      'Network' => $this->network,
                      'ServerType' => $this->server_type,
                      'ServiceLevel' => $this->service_level,
                      'Cpu' => $this->cpu,
                      'MemoryGB' => $this->memory,
                      'Password' => $this->pswd);
      $response = $this->invoke('/Server/CreateServer/JSON', $params);
      if (isset($response['StatusCode']) && $response['StatusCode'] === 0) {
        if ($this->debug) bm_log_msg(sprintf('Successfully provisioned instance with request ID %s', $response['RequestID']), basename(__FILE__), __LINE__);
        $provisioned = $response['RequestID'];
      }
      else bm_log_msg(sprintf('Unable to provision instance. Status Code: %d; Message: %s', isset($response['StatusCode']) ? $response['StatusCode'] : 'unknown', isset($response['Message']) ? $response['Message'] : 'unknown'), basename(__FILE__), __LINE__, TRUE);
    }
    return $provisioned;
  }
  
  /**
   * invoked directly following instantiation. Implementations should return 
   * TRUE if runtime parameters are set and valid. If invalid, it should return 
   * an error message string. this method may be used to validate credentials 
   * and compute instance parameters
   */
  function validate() {
    if (!isset($this->validated)) {
      $this->group_id = getenv('bm_param_tier3_group');
      $no_group_set = $this->group_id ? FALSE : TRUE;
      // Network
      $this->network = getenv('bm_param_tier3_network');
      // ServerType
      $this->server_type = getenv('bm_param_tier3_server_type');
      $this->server_type = $this->server_type == 1 || $this->server_type == 2 ? $this->server_type*1 : 1;
      // ServiceLevel
      $this->service_level = getenv('bm_param_tier3_service_level');
      $this->service_level = $this->service_level == 1 || $this->service_level == 2 ? $this->service_level*1 : 2;
      
      // validate auth credentials
      $response = $this->invoke('/Auth/Logon/', array('APIKey' => $this->api_key, 'Password' => $this->api_secret));
      if (isset($response['StatusCode'])) {
        if ($response['StatusCode'] === 0) {
          // validate region
          $response = $this->invoke('/Account/GetLocations/JSON');
          if (isset($response['StatusCode']) && $response['StatusCode'] === 0) {
            if ($this->debug) bm_log_msg(sprintf('Successfully logged on to the Tier 3 API using APIKey %s', $this->api_key), basename(__FILE__), __LINE__);
            $region_valid = FALSE;
            foreach($response['Locations'] as $location) {
              if (trim(strtolower($location['Region'])) == trim(strtolower($this->region))) $this->region = $location['Region'];
              if (trim(strtolower($location['Alias'])) == trim(strtolower($this->region))) $region_valid = TRUE;
            }
            if (!$region_valid) {
              $this->validated = sprintf('API region %s is not valid', $this->region);
              bm_log_msg($this->validated, basename(__FILE__), __LINE__, TRUE);
            }
            else {
              if ($this->debug) bm_log_msg(sprintf('Successfully validated region %s', $this->region), basename(__FILE__), __LINE__);
              // validate OS template
              $response = $this->invoke('/Server/ListAvailableServerTemplates/JSON', array('Location' => $this->region));
              if (isset($response['StatusCode']) && $response['StatusCode'] === 0) {
                $os_valid = FALSE;
                foreach($response['Templates'] as $template) {
                  if (trim(strtolower($template['Name'])) == trim(strtolower($this->os))) $os_valid = TRUE;
                }
                if (!$os_valid) {
                  $this->validated = sprintf('OS template %s is not valid', $this->os);
                  bm_log_msg($this->validated, basename(__FILE__), __LINE__, TRUE);
                }
                else {
                  if ($this->debug) bm_log_msg(sprintf('Successfully validated OS template %s', $this->os), basename(__FILE__), __LINE__);
                  // validate/set group_id
                  $response = $this->invoke('/Group/GetGroups/JSON', array('Location' => $this->region));
                  if (isset($response['StatusCode']) && $response['StatusCode'] === 0) {
                    $group_valid = FALSE;
                    foreach($response['HardwareGroups'] as $group) {
                      // group not set
                      if (!$this->group_id || ($no_group_set && preg_match('/default/i', $group['Name']))) $this->group_id = $group['ID'];
                      if (trim(strtolower($group['Name'])) == trim(strtolower($this->group_id))) $this->group_id = $location['ID'];
                      if (trim(strtolower($group['ID'])) == trim(strtolower($this->group_id))) $group_valid = TRUE;
                    }
                    if (!$group_valid) {
                      $this->validated = sprintf('tier3_group %s is not valid', $this->group_id);
                      bm_log_msg($this->validated, basename(__FILE__), __LINE__, TRUE);
                    }
                    else {
                      if ($this->debug) bm_log_msg(sprintf('Successfully validated tier3_group %s', $this->group_id), basename(__FILE__), __LINE__);
                      // validate type and set cpu/memory attributes
                      if (preg_match('/^([0-9]+)cpu\-([0-9]+)gb$/i', $this->type, $m)) {
                        $this->cpu = $m[1]*1;
                        $this->memory = $m[2]*1;
                        if ($this->cpu < 1 || $this->cpu > TIER3_MAX_CPU) {
                          $this->validated = sprintf('type %s is not valid - cpu must be between 1 and %d', $this->type, TIER3_MAX_CPU);
                          bm_log_msg($this->validated, basename(__FILE__), __LINE__, TRUE);
                        }
                        else if ($this->memory < 1 || $this->memory > TIER3_MAX_MEMORY) {
                          $this->validated = sprintf('type %s is not valid - memory must be between 1 and %d', $this->type, TIER3_MAX_MEMORY);
                          bm_log_msg($this->validated, basename(__FILE__), __LINE__, TRUE);
                        }
                        else {
                          if ($this->debug) bm_log_msg(sprintf('Successfully validated type %s. %d cpu; %d GB memory', $this->type, $this->cpu, $this->memory), basename(__FILE__), __LINE__);
                          // validate network
                          $response = $this->invoke('/Network/GetDeployableNetworks/JSON', array('Location' => $this->region));
                          if (isset($response['StatusCode']) && $response['StatusCode'] === 0) {
                            $network_valid = FALSE;
                            foreach($response['Networks'] as $network) {
                              // group not set
                              if (!$this->network) $this->network = $network['Name'];
                              if (trim(strtolower($network['Name'])) == trim(strtolower($this->network))) $network_valid = TRUE;
                            }
                            if (!$network_valid) {
                              $this->validated = sprintf('tier3_network %s is not valid', $this->network);
                              bm_log_msg($this->validated, basename(__FILE__), __LINE__, TRUE);
                            }
                            else {
                              if ($this->debug) bm_log_msg(sprintf('Successfully validated network %s', $this->network), basename(__FILE__), __LINE__);
                              $this->validated = TRUE;
                            }
                          }
                          else {
                            $this->validated = sprintf('Unable to query networks');
                            bm_log_msg(sprintf('Unable to query networks. Status Code: %d; Message: %s', $this->group_id, isset($response['StatusCode']) ? $response['StatusCode'] : 'unknown', isset($response['Message']) ? $response['Message'] : 'unknown'), basename(__FILE__), __LINE__, TRUE);
                          }
                        }
                      }
                      else {
                        $this->validated = sprintf('type %s is not valid - this value must match the expression /^([0-9]+)cpu\-([0-9]+)gb$/i', $this->type, TIER3_MAX_MEMORY);
                        bm_log_msg($this->validated, basename(__FILE__), __LINE__, TRUE);
                      }
                    }
                  }
                  else {
                    $this->validated = sprintf('Unable to validate or set hardware group %s', $this->group_id);
                    bm_log_msg(sprintf('Unable validate hardware group %s. Status Code: %d; Message: %s', $this->group_id, isset($response['StatusCode']) ? $response['StatusCode'] : 'unknown', isset($response['Message']) ? $response['Message'] : 'unknown'), basename(__FILE__), __LINE__, TRUE);
                  }
                }
              }
              else {
                $this->validated = sprintf('Unable to validate OS template %s', $this->os);
                bm_log_msg(sprintf('Unable validate OS template %s. Status Code: %d; Message: %s', $this->os, isset($response['StatusCode']) ? $response['StatusCode'] : 'unknown', isset($response['Message']) ? $response['Message'] : 'unknown'), basename(__FILE__), __LINE__, TRUE);
              }
            }
          }
          else {
            $this->validated = sprintf('Unable to validate region %s', $this->region);
            bm_log_msg(sprintf('Unable validate API region %s. Status Code: %d; Message: %s', $this->region, isset($response['StatusCode']) ? $response['StatusCode'] : 'unknown', isset($response['Message']) ? $response['Message'] : 'unknown'), basename(__FILE__), __LINE__, TRUE);
          }
        }
        else {
          $this->validated = sprintf('Unable to logon to the Tier 3 API using APIKey %s', $this->api_key);
          bm_log_msg(sprintf('Unable logon to the Tier 3 API using APIKey %s. Status Code: %d; Message: %s', $this->api_key, $response['StatusCode'], $response['Message']), basename(__FILE__), __LINE__, TRUE);
        }
      }
      else {
        $this->validated = sprintf('Unable to invoke API logon command /Auth/Logon/ using APIKey %s', $this->api_key);
        bm_log_msg($this->validated, basename(__FILE__), __LINE__, TRUE);
      }
    }
    return $this->validated;
  }
  
  /**
   * returns the URL to use for an API call
   * @param string $command the API command being invoked (e.g. '/Auth/Logon/')
   * @return string
   */
  private function get_api_url($command) {
    return ($this->api_endpoint ? $this->api_endpoint : TIER3_API_ENDPOINT) . $command;
  }
  
  /**
   * invokes an API call and returns the result as an associative array. 
   * returns NULL if the call is not successful (i.e. http request cannot be 
   * fulfilled, not error in the API response)
   * @param string $command the API command to invoke
   * @param array $params optional parameters to pass to the call
   * @return array
   */
  private function invoke($command, $params=NULL) {
    $payload = $params ? json_encode($params) : NULL;
    $cli = sprintf('curl -X POST -H "Content-Type:application/json" -H "Content-Length:%d" -b %s -c %s %s %s', $params ? strlen($payload) : 0, TIER3_COOKIEJAR, TIER3_COOKIEJAR, $this->get_api_url($command), $params ? sprintf(" -d '%s'", $payload) : '');
    if ($this->debug) bm_log_msg(sprintf('Invoking API command using cli string %s', $cli), basename(__FILE__), __LINE__);
    $output = shell_exec($cli);
    if ($this->debug) bm_log_msg(sprintf('Got API response: %s', $output), basename(__FILE__), __LINE__);
    $result = json_decode(trim($output), TRUE);
    if (!is_array($result)) bm_log_msg(sprintf('Unable to json decode response for API request %s', $command), basename(__FILE__), __LINE__, TRUE);
    return $result;
  }
  
}
?>

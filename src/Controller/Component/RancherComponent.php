<?php
namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\Network\Http\Client;
use Cake\Utility\Hash;
use Cake\Network\Exception\InternalErrorException;

class RancherComponent extends Component
{
    public $loadbalancers       = []; 
    public $defaultHttpOptions  = [];   

    public function initialize(array $config)
    {
        //Set default options
        $this->defaultHttpOptions = [
            'headers' => ['Accept' => 'application/json']
        ];

        //Append credentials if set
        if( !empty(env('RANCHER_API_ACCESS_KEY')) && !empty(env('RANCHER_API_SECRET_KEY')) ){
            $this->defaultHttpOptions['auth'] = ['username' => env('RANCHER_API_ACCESS_KEY'), 'password' => env('RANCHER_API_SECRET_KEY')];
        }
    }

    public function getLoadbalancers()
    {
        $http = new Client();

        //Hit Rancher API for loadbalancers
        $apiResponse = $http->get(env('RANCHER_API_URL').'/loadbalancerservices', [],$this->defaultHttpOptions)->json;

        //Check response
        if(!isset($apiResponse['data'])){
            throw new InternalErrorException('Error in Rancher API json response: '.var_dump($apiResponse));
        }

        //Extract the results
        $loadbalancers = Hash::extract($apiResponse,'data.{n}');

        //Return empty set if no lb's
        if( empty($loadbalancers) )
        {
            return [];
        }

        //Add the Services the LB is linked to (can be multiple)
        array_walk($loadbalancers,[$this,'addLoadbalancerService']);

        //Add the stack the service is part of (called environment)
        array_walk($loadbalancers,[$this,'addLoadbalancerEnvironment']);

        //Add the project (environment in Rancher UI) the environment is part
        array_walk($loadbalancers,[$this,'addLoadbalancerProject']);

        //Reduce the amount of data
        array_walk($loadbalancers,[$this,'reduceData'], ['links','actions','launchConfig','loadBalancerConfig']);

        return $loadbalancers;
    }

    private function addLoadbalancerService(&$loadbalancer, $key)
    {

        //Get the links.consumedservices endpoint to get the service name
        $http = new Client();

        //Hit Rancher API for consumedservices info
        $apiResponse = $http->get($loadbalancer['links']['consumedservices'], [], $this->defaultHttpOptions)->json;

        //Extract the results
        $loadbalancer['consumedservices'] = Hash::extract($apiResponse,'data.{n}.name');

        if(empty($loadbalancer['consumedservices']))
        {
            throw new InternalErrorException('No services found for : '.$loadbalancer['name'].' (Error: '.print_r($apiResponse).")");
        }
    }

    private function addLoadbalancerEnvironment(&$loadbalancer, $key)
    {

        //Get the links.environment endpoint to get the service name
        $http = new Client();

        //Hit Rancher API for environment info
        $apiResponse = $http->get($loadbalancer['links']['environment'], [], $this->defaultHttpOptions)->json;

        //Extract the results
        if( ! empty($apiResponse['name'])){
             $loadbalancer['environment'] = $apiResponse['name'];
        }else{
            throw new InternalErrorException('Cannot find environment name for : '.$loadbalancer['name'].' (Error: '.print_r($apiResponse).")");
        }

    }

    private function addLoadbalancerProject(&$loadbalancer, $key)
    {

        //Get the root endpoint
        $http = new Client();

        //Hit Rancher API for project info
        $apiResponse = $http->get(env('RANCHER_API_URL'), [], $this->defaultHttpOptions)->json;

        //Extract the results
        if( ! empty($apiResponse['name'])){
             $loadbalancer['project'] = $apiResponse['name'];
        }else{
            throw new InternalErrorException('Cannot find project name for : '.$loadbalancer['name'].' (Error: '.print_r($apiResponse).")");
        }

    }

    private function reduceData(&$loadbalancer, $key, $keysToRemove = [])
    {

        //Unset some stuff
        foreach($keysToRemove as $index){
            unset($loadbalancer[$index]);
        }

    }


}
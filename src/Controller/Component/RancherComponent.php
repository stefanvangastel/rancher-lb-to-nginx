<?php
namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\Network\Http\Client;
use Cake\Utility\Hash;

class RancherComponent extends Component
{
    public $loadbalancers = [];    

    public function getLoadbalancers()
    {

        $http = new Client();

        //Hit Rancher API for loadbalancers
        $apiResponse = $http->get(env('RANCHER_API_URL').'/loadbalancerservices', [], [
            'headers' => ['Accept' => 'application/json'],
            'type' => 'json'
        ])->json;

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
        array_walk($loadbalancers,[$this,'reduceData'],['links','actions','launchConfig','loadBalancerConfig']);

        return $loadbalancers;
    }

    private function addLoadbalancerService(&$loadbalancer, $key)
    {

        //Get the links.consumedservices endpoint to get the service name
        $http = new Client();

        //Hit Rancher API for consumedservices info
        $apiResponse = $http->get($loadbalancer['links']['consumedservices'], [], [
            'headers' => ['Accept' => 'application/json'],
            'type' => 'json'
        ])->json;

        //Extract the results
        $loadbalancer['consumedservices'] = Hash::extract($apiResponse,'data.{n}.name');
    }

    private function addLoadbalancerEnvironment(&$loadbalancer, $key)
    {

        //Get the links.environment endpoint to get the service name
        $http = new Client();

        //Hit Rancher API for environment info
        $apiResponse = $http->get($loadbalancer['links']['environment'], [], [
            'headers' => ['Accept' => 'application/json'],
            'type' => 'json'
        ])->json;

        //Extract the results
        if( ! empty($apiResponse['name'])){
             $loadbalancer['environment'] = $apiResponse['name'];
        }

    }

    private function addLoadbalancerProject(&$loadbalancer, $key)
    {

        //Get the root endpoint
        $http = new Client();

        //Hit Rancher API for project info
        $apiResponse = $http->get(env('RANCHER_API_URL'), [], [
            'headers' => ['Accept' => 'application/json'],
            'type' => 'json'
        ])->json;

        //Extract the results
        if( ! empty($apiResponse['name'])){
             $loadbalancer['project'] = $apiResponse['name'];
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
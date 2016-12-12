<?php
namespace App\Shell;

use Cake\Console\Shell;

use Cake\Core\App;
use Cake\Controller\Component;
use Cake\Controller\ComponentRegistry;
use App\Controller\Component\RancherComponent;  
use App\Controller\Component\NginxComponent;  

class SyncNginxRancherShell extends Shell
{

    public function initialize()
    {
       $this->Rancher = new RancherComponent(new ComponentRegistry());
       $this->Nginx = new NginxComponent(new ComponentRegistry());
    }

    public function main()
    {
        $this->verbose('Starting to sync Rancher loadbalancers with Nginx vhosts...');

        //Get Rancher loadbalancers
        $loadbalancers = $this->Rancher->getLoadbalancers();

        $this->verbose("Found ".count($loadbalancers)." loadbalancers");

        //Sync Nginx vhosts
        $fqdns = $this->Nginx->sync($loadbalancers);

        //Update DNS through API
        debug($fqdns);

    }


}

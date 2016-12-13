<?php
namespace App\Shell;

use Cake\Console\Shell;

use Cake\Core\App;
use Cake\Controller\Component;
use Cake\Controller\ComponentRegistry;
use App\Controller\Component\RancherComponent;  
use App\Controller\Component\NginxComponent;
use Cake\Network\Exception\InternalErrorException;  

class SyncNginxRancherShell extends Shell
{

    public function initialize()
    {

    }

    public function main()
    {
        //Script must run as root
        if(trim(`whoami`) != 'root'){
            throw new InternalErrorException('Script must run as root, you are running it as :'.`whoami`); 
        }

        //Check already running process
        exec("ps -ef | grep sync_nginx_rancher | grep -v grep", $output, $result);
        if(count($output)>1) throw new InternalErrorException('Script already running: '.implode("\n",$output));

        $this->out('Starting to sync Rancher loadbalancers with Nginx vhosts and DDNS...');

        //Keep running
        $iteration = 1;
        while(true){
            //Run the logic
            $this->run();

            //Feedback
            $this->out("Done running iteration $iteration\n");

            //Timout
            sleep(5);
            
            //Iterate
            $iteration++;
        }

    }

    public function run(){

        //Create NEW instances of components
        $this->Rancher = new RancherComponent(new ComponentRegistry());
        $this->Nginx = new NginxComponent(new ComponentRegistry());

        //Get Rancher loadbalancers
        $loadbalancers = $this->Rancher->getLoadbalancers();

        $this->out("Found ".count($loadbalancers)." loadbalancers");

        //Sync Nginx vhosts
        $fqdns = $this->Nginx->sync($loadbalancers);

        $this->out("Found ".count($fqdns['create'])." new or modified FQDN's : \n- ".implode("\n- ",$fqdns['create']));

        //Update DNS through API
    }


}

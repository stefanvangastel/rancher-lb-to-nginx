<?php
namespace App\Controller\Component;

use Cake\Controller\Component;
use Cake\Utility\Hash;
use RomanPitak\Nginx\Config\Scope;
use RomanPitak\Nginx\Config\Directive;

use Cake\Log\Log;

class NginxComponent extends Component
{
    //All vhost config files with RANCHER_ prefix
    public $currentVhosts   = [];
    public $newVhosts       = [];
    public $fqdns           = ['create'=>[],'remove'=>[]];
  
    public function sync($loadbalancers = [])
    {
        if(empty($loadbalancers))
        {
            return false;
        }

        //Get all RANCHER_ vhosts
        $this->currentVhosts = $this->getVhosts();

        //Create new vhost files 
        $this->newVhosts = $this->createVhosts($loadbalancers);

        //Remove deprecated vhosts 
        $this->removeDeprecatedVhosts();

        //Remove unchanged vhosts (some sort of 'update')
        $this->removeUnchangedVhosts();

        //Write new vhosts (or update / overwrite existing ones)
        $this->writeNewVhosts();

        //Config test 
        //TODO

        //Reload Nginx
        //

        //Return the FQDN list to update DNS
        return $this->fqdns;
    }

    private function getVhosts()
    {
        $currentVhosts = [];

        //Path
        $path = env('NGINX_SITES_DIR').'RANCHER_*.conf';

        //Read config files starting with RANCHER_
        foreach(glob($path) as $filename){
            //Add to current hosts
            $currentVhosts[basename($filename)] = file_get_contents($filename);
        }

        Log::write('debug', 'Found '.count($currentVhosts).' current vhosts');
        
        return $currentVhosts;
    }

    private function createVhosts($loadbalancers)
    {

        $newVhosts = [];

        foreach($loadbalancers as $loadbalancer)
        {

            //For each service it exposes (since 1 lb can connect to multiple services):
            foreach($loadbalancer['consumedservices'] as $serviceName)
            {
                //Create FQDN based on template settings
                $fqdn = $this->generateFQDN($loadbalancer,$serviceName);

                //Create unique id for the combination of servicename and loadbalancer uid
                $upstreamName = md5($loadbalancer['uuid'].$serviceName);

                //Create upstream servers
                $upstreamServers = $this->createUpstreamServers($loadbalancer);
                
                //Create config template string
                $vhostConfigString = $this->createConfigTemplateString($upstreamName, $upstreamServers, $fqdn);

                //Append to vhosts 
                $newVhosts["RANCHER_$fqdn.conf"] = $vhostConfigString;

                //Append FQDN to array
                $this->fqdns['create'][] = $fqdn;

                Log::write('debug', 'Created vhost config string for '.$fqdn);
            }
        }

        Log::write('debug', 'Created '.count($newVhosts).' new vhost config strings');
        
        return $newVhosts;
    }

    private function generateFQDN($loadbalancer,$serviceName)
    {

        //Tmp append service to loadbalancer
        $loadbalancer['service'] = $serviceName;

        //Define components
        $components = ['SERVICE','ENVIRONMENT','PROJECT'];

        //Replace the components in the template 
        $fqdn = env('FQDN_TEMPLATE');
        
        foreach($components as $component){
            $fqdn = str_replace('{{'.$component.'}}', $loadbalancer[strtolower($component)], $fqdn);
        }

        return strtolower($fqdn);
    }

    private function createUpstreamServers($loadbalancer)
    {

        $upstreamServers = null;

        foreach($loadbalancer['publicEndpoints'] as $endpoint)
        {
            $upstreamServers .= Directive::create("server", $endpoint["ipAddress"].":".$endpoint["port"]);
        }

        return rtrim($upstreamServers);
    }

    
    private function createConfigTemplateString($upstreamName, $upstreamServers, $fqdn)
    {

        $vhostConfig = Scope::create()
            ->addDirective(Directive::create('upstream', $upstreamName)
                ->setChildScope(Scope::create()
                    ->addDirective(Directive::create('#UPSTREAM_PLACEHOLDER'))
                )
            )
            ->addDirective(Directive::create('server')
                ->setChildScope(Scope::create()
                    ->addDirective(Directive::create('server_name', $fqdn))
                    ->addDirective(Directive::create('location', '/', Scope::create()
                            ->addDirective(Directive::create('proxy_pass', 'http://'.$upstreamName))
                        )
                    )
                )
            );
        
        $configString = $vhostConfig->prettyPrint(-1);

        //Replace upstream placeholder 
        return str_replace('#UPSTREAM_PLACEHOLDER;',$upstreamServers,$configString);
    }

    private function removeUnchangedVhosts(){

        //Check new in current:
        foreach($this->newVhosts as $vhostname => $newVhostconfig){

            //If the same, unset the new one (less writing is better)
            if( isset($this->currentVhosts[$vhostname]) AND (md5($this->currentVhosts[$vhostname]) == md5($newVhostconfig)) ){
                //Remove duplicate from new
                unset($this->newVhosts[$vhostname]);

                Log::write('debug', 'Skipping existing and unchanged '.$vhostname);
            }
        }
    }

    private function removeDeprecatedVhosts(){

        $depVhosts = array_diff_key($this->currentVhosts, $this->newVhosts);

        if( empty($depVhosts) )return;

        //Remove old vhosts
        foreach($depVhosts as $vhostToRemove => $vhostContents){
            unlink(env('NGINX_SITES_DIR'). $vhostToRemove); 

            //Add to remove list
            $this->fqdns['remove'][] = str_replace(['RANCHER_','.conf'],'',$vhostToRemove);

            Log::write('debug', 'Deleted deprecated '.$vhostToRemove.' vhost config file');
        }
    }

    private function writeNewVhosts(){

        if( empty($this->newVhosts) )return;

        $path = env('NGINX_SITES_DIR');

        foreach($this->newVhosts as $filename => $filecontent){
            //Write or update vhost files
            file_put_contents($path.$filename, $filecontent);

            Log::write('debug', 'Wrote new (or updated) '.$path.$filename.' vhost config file');
        }

    }


}
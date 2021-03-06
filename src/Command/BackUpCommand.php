<?php
namespace Backup\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use GuzzleHttp\Client;

class BackUpCommand extends Command
{
    protected function configure()
    {
        $this->setName('backup')
            ->setDescription('Downloads and stores the latest backups from govCMS SaaS')
            ->addOption(
                'api-username',
                null,
                InputOption::VALUE_REQUIRED,
                'The ACSF API username'
            )
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'The ACSF API key'
            )
            ->addOption(
                'cloud-username',
                null,
                InputOption::VALUE_REQUIRED,
                'The Cloud API username'
            )
            ->addOption(
                'cloud-key',
                null,
                InputOption::VALUE_REQUIRED,
                'The Cloud API key'
            )
            ->addOption(
                'destination',
                null,
                InputOption::VALUE_REQUIRED,
                'The Destination for backup files'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $start_time = time();
        $day_of_week = date('l');
        $destination = $input->getOption('destination');
        $FULL_DAY_TO_RUN = 'Sunday';
        $BACKUP_DATE_DIR = date('Y-m-d');
        mkdir($destination . "/" . $BACKUP_DATE_DIR);
        $destination = $destination . "/" . $BACKUP_DATE_DIR . "/";

        $START_PHP = "<?php ";
        $TEMPLATE = "\n
\$aliases['%%ALIASNAME%%'] = array(
    'uri' => '%%ALIASNAME%%',
    'root' => '%%ROOT%%',
    'remote-host' => '%%REMOTEHOST%%',
    'remote-user' => '%%REMOTEUSER%%',
    'ssh-options' => '-o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -F /dev/null',
    'path-aliases' => array(
      '%drush-script' => 'drush6',
      '%dump-dir' => '/mnt/tmp/',
    ),
    'command-specific' => array(
      'sql-dump' => array(
         'no-ordered-dump' => TRUE,
         'structure-tables-list' => 'apachesolr_index_entities,apachesolr_index_entities_node,authmap,cache,cache_apachesolr,cache_block,cache_bootstrap,cache_entity_comment,cache_entity_file,cache_entity_node,cache_entity_taxonomy_term,cache_entity_taxonomy_vocabulary,cache_entity_user,cache_field,cache_file_styles,cache_filter,cache_form,cache_image,cache_libraries,cache_menu,cache_metatag,cache_page,cache_panels,cache_path,cache_rules,cache_styles,cache_token,cache_update,cache_views,cache_views_data,captcha_sessions,ctools_css_cache,ctools_object_cache,flood,forward_log,forward_statistics,history,queue,sessions,watchdog',
      ),
    ),
);";

        $alias_file = fopen($destination . "/govcms.aliases.drushrc.php", 'w');
        $sites_file = fopen($destination . "/sites.txt", 'w');

        $lines = file(dirname(__FILE__) . "/../../conf/config", FILE_IGNORE_NEW_LINES);
        $ignore = file(dirname(__FILE__) . "/../../conf/ignore", FILE_IGNORE_NEW_LINES);

        print "Running govCMS Backups for " . $day_of_week . ".";

        $client = new Client([
            'base_uri' => 'https://www.govcms.acsitefactory.com/api/v1/',
            'auth' => [$input->getOption('api-username'), $input->getOption('api-key')],
        ]);

        $result_size = 1;
        $temp_site_list = array();
        $page = 1;
        while ($result_size > 0) {
            $result = json_decode($client->request('GET', 'sites', array('query' => array('page' => $page, 'limit' => 100)))->getBody());
            $result_size = sizeof($result->sites);
            $temp_site_list = array_merge($temp_site_list, $result->sites);
            $page++;
        }

        print "\nFound " . sizeof($temp_site_list) . " total sites on SaaS.\n";
        $site_list = array();
        foreach ($temp_site_list as $key => $value) {
            $result = json_decode($client->request('GET', 'sites/' . $value->id)->getBody());
            if ($day_of_week == $FULL_DAY_TO_RUN) {
                $site_list[] = $result;
            } else if (isset($result->is_primary) && $result->is_primary) {
                $site_list[] = $result;
            }
        }
        print "\nUsing " . sizeof($site_list) . " sites on SaaS.\n";
        $client = new Client([
            'base_uri' => 'https://cloudapi.acquia.com/v1/',
            'auth' => [$input->getOption('cloud-username'), $input->getOption('cloud-key')],
        ]);
        $sites = json_decode($client->request('GET', 'sites.json')->getBody());
        $paas_size = 0;
        foreach ($sites as $site) {
            if(substr($site, 0, strlen('prod:')) === 'prod:') {
                $paas_size++;
                $endpoint = implode('/', ['sites', $site, 'envs', 'prod.json']);
                $server = json_decode($client->request('GET', $endpoint)->getBody());
                $paas = new \stdClass();
                $paas->id = 0;
                $paas->stack_id = 0;
                $paas->site = $server->default_domain;
                $paas->domains = array($server->default_domain);
                $paas->root = '/var/www/html/'.$server->unix_username.'/docroot';
                $paas->remote_host = $server->ssh_host;
                $paas->remote_user = $server->unix_username;

                $the_domains = array();

                $endpoint = implode('/', ['sites', $site, 'envs', 'prod', 'domains.json']);
                $domains = json_decode($client->request('GET', $endpoint)->getBody());
                foreach ($domains as $domain) {
                    $the_domains[] = $domain->name;
                }

                //Let's also add a *.govcms.gov.au URL for PaaS sites
                $govcms_domain = strtok($paas->site, '.');
                $the_domains[] = $govcms_domain.'.govcms.gov.au';

                $paas->collection_domains = $the_domains;
                $ignored = false;
                foreach ($ignore as $item) {
                    if(in_array($item, $paas->domains) || in_array($item, $paas->collection_domains)) {
                        $ignored = true;
                    }
                }
                if(!$ignored) {
                    $site_list[] = $paas;
                }
            }
        }
        print "Using " . $paas_size . " sites on PaaS.\n";

        print "Using " . sizeof($site_list) . " sites.\n";

        $temp_count = 0;
        $alias_file_content = $START_PHP;
        $sites_file_content = "";
        foreach ($site_list as $site) {
            $single_alias = $TEMPLATE;
            $root = "";
            $remote_host = "";
            $remote_user = "";
            if (isset($site->stack_id) && $site->stack_id > 0) {
                foreach ($lines as $line) {
                    $temp_array = explode(" ", $line);
                    if ($temp_array[0] == $site->stack_id) {
                        $root = $temp_array[3];
                        $remote_host = $temp_array[2];
                        $remote_user = $temp_array[1];
                    }
                }
            } else {
                $root = $site->root;
                $remote_host = $site->remote_host;
                $remote_user = $site->remote_user;
            }
            $variables = array("ALIASNAME" => $site->domains[0], "ROOT" => $root, "REMOTEHOST" => $remote_host, "REMOTEUSER" => $remote_user);

            foreach ($variables as $key => $value) {
                $single_alias = str_replace('%%' . $key . '%%', $value, $single_alias);
            }
            $alias_file_content .= $single_alias;
        }

        fwrite($alias_file, $alias_file_content);

        print("\nCopying file [" . $destination . "govcms.aliases.drushrc.php] to [/home/govcms/.drush/govcms.aliases.drushrc.php]");
        copy($destination . "govcms.aliases.drushrc.php", "/home/govcms/.drush/govcms.aliases.drushrc.php");
        $list_of_files = array();

        foreach ($site_list as $site) {
            $temp_count++;
            $start = time();
            print "\n***************************\n";
            print "Starting Backup of " . $site->site . " [" . $site->domains[0] . "] #" . $temp_count . "/" . sizeof($site_list) . "\n";
            exec("drush @" . $site->domains[0] . " ssh 'mkdir /mnt/tmp/backups;chmod 777 /mnt/tmp/backups'");
            exec("drush @" . $site->domains[0] . " archive-dump --destination=/mnt/tmp/backups/" . $site->domains[0] . ".tar.gz --overwrite  --tar-options=\"--exclude=sites/default/files/* --exclude=sites/default/files.bak/* --exclude=sites/default/files-private/* --exclude=sites/default/private-files/*\"");
            print "Dump completed.\n";
            mkdir($destination . $site->domains[0]);
            print "Retrieving " . $site->site . " [" . $site->domains[0] . "] dump.\n";
            exec("drush -y rsync --remove-source-files @" . $site->domains[0] . ":/mnt/tmp/backups/" . $site->domains[0] . ".tar.gz " . $destination . $site->domains[0] . "/ > /dev/null 2>/dev/null &");
            $list_of_files[] = $site->domains[0] . ".tar.gz";
            $domains = $site->domains[0];
            if (isset($site->collection_domains) && !empty($site->collection_domains)) {
                $domains = implode(" ", $site->collection_domains);
                $domains .= " " . $site->domains[0];
            }
            $sites_file_content .= $site->domains[0] . " " . $site->domains[0] . ".tar.gz " . $destination . $site->domains[0] . "/" . $site->domains[0] . ".tar.gz " . $site->id . " \"" . $domains . "\"\n";
            $total = time() - $start;
            $total_time = time() - $start_time;
            print "\n" . $site->site . " took " . $total . " seconds out of total " . $total_time . " seconds.";
            print "\n***************************\n";
        }
        fwrite($sites_file, $sites_file_content);
        copy($destination . "sites.txt", "/home/govcms/.drush/sites.txt");
        sleep(120);


        $di = new \RecursiveDirectoryIterator($destination);
        $file_list = array();
        foreach (new \RecursiveIteratorIterator($di) as $filename => $file) {
            $info = new \SplFileInfo($filename);
            if (is_File($file) && $info->getExtension() == 'gz') {
                print "\n" . basename($filename) . " - " . $file->getSize() . " bytes";
                $file_list[] = basename($filename);
            }
        }

        if (sizeof($file_list) == sizeof($list_of_files)) {
            print "\nComplete SUCCESSFULLY.";
        } else {
            print "\nComplete INCORRECT NUMBERS got " . sizeof($file_list) . " expected " . sizeof($list_of_files);
            $diff = array_diff($file_list, $list_of_files);
            foreach ($diff as $d) {
                print "\n" . $d;
            }
        }
    }
}

?>

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

        if (getenv('LAGOON_OVERRIDE_SSH')) {
            $ssh = getenv('LAGOON_OVERRIDE_SSH');
        } else {
            $ssh = "ssh-lagoon.govcms.amazee.io:30831";
        }
        list ($ssh_host, $ssh_port) = explode(":", $ssh);

        if (getenv('LAGOON_OVERRIDE_API')) {
            $api = getenv('LAGOON_OVERRIDE_API');
        } else {
            $api = "https://api-lagoon.govcms.amazee.io";
        }
        $api_url = "$api/graphql";


        if (getenv('LAGOON_OVERRIDE_JWT_TOKEN')) {
            $jwt_token = getenv('LAGOON_OVERRIDE_JWT_TOKEN');
        } else {
            exec("ssh -p $ssh_port -o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -t lagoon@$ssh_host token 2>&1", $token_array, $rc);
            if ($rc !== 0) {
              print "Could not load API JWT Token, error was: '" . implode(",", $token_array);
              exit(1);
            }
            $jwt_token = $token_array[0];
        }

        $query = '{
            allProjects {
              name
              environments(type:PRODUCTION) {
                name
                openshiftProjectName
                routes
                route
              }
            }
          }
        ';

        $curl = curl_init($api_url);

        // Build up the curl options for the GraphQL query. When using the content type
        // 'application/json', graphql-express expects the query to be in the json
        // encoded post body beneath the 'query' property.
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_POSTREDIR, CURL_REDIR_POST_ALL);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', "Authorization: Bearer $jwt_token"]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array(
        'query' => $query,
        )));
        $response = curl_exec($curl);

        // Check if the curl request succeeded.
        if ($response === FALSE) {
            $info = var_export(curl_getinfo($curl), TRUE);
            $error = curl_error($curl);
            curl_close($curl);

            print "Lagoon API ERROR:";
            print $info;
            print $error;
            exit(1);
        }

        curl_close($curl);
        $response = json_decode($response);

        $START_PHP = "<?php ";
        $TEMPLATE = "\n
\$aliases['%%ALIASNAME%%'] = array(
    'uri' => '%%URI%%',
    'remote-host' => '$ssh_host',
    'remote-user' => '%%ALIASNAME%%',
    'ssh-options' => '-o LogLevel=ERROR -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -p $ssh_port',
    'command-specific' => array(
      'sql-dump' => array(
         'no-ordered-dump' => TRUE,
         'structure-tables-list' => 'apachesolr_index_entities,apachesolr_index_entities_node,authmap,cache,cache_*,captcha_sessions,ctools_css_cache,ctools_object_cache,flood,forward_log,forward_statistics,history,queue,sessions,watchdog',
      ),
    ),
);";

        $alias_file = fopen($destination . "/govcms.aliases.drushrc.php", 'w');
        $sites_file = fopen($destination . "/sites.txt", 'w');


        print "Running govCMS Backups for " . $day_of_week . ".";

        $temp_count = 0;
        $alias_file_content = $START_PHP;
        $sites_file_content = "";
        foreach ($response->data->allProjects as $project) {
            if (count($project->environments) == 0) {
                print "\nINFO: No production environment found for $project->name";
            }
            foreach ($project->environments as $environment) {
                $alias_file_content .= str_replace([ '%%ALIASNAME%%', '%%URI%%' ] , [ $environment->openshiftProjectName, $environment->route ] , $TEMPLATE);
                $site_list[] = (object) ['name' => $environment->openshiftProjectName, 'routes' => $environment->routes];
            }
        }

        fwrite($alias_file, $alias_file_content);

        print("\nCopying file [" . $destination . "govcms.aliases.drushrc.php] to [/home/svc_fin_gcms_p_mgt/.drush/govcms.aliases.drushrc.php]");
        mkdir('/home/svc_fin_gcms_p_mgt/.drush/', 0777, true);
        copy($destination . "govcms.aliases.drushrc.php", "/home/svc_fin_gcms_p_mgt/.drush/govcms.aliases.drushrc.php");
        $list_of_files = array();

        foreach ($site_list as $site) {
            $temp_count++;
            $start = time();
            print "\n***************************\n";
            print "Starting Backup of " . $site->name . " #" . $temp_count . "/" . sizeof($site_list) . "\n";
            exec("drush @" . $site->name . " archive-dump --destination=/tmp/dr-backups/" . $site->name . ".tar.gz --overwrite --tar-options=\"--exclude=sites/default/files/* --exclude=sites/default/files.bak/* --exclude=sites/default/files/private/* \"", $output);
            print_r($output);
            print "Dump completed.\n";
            mkdir($destination . $site->name);
            print "Retrieving " . $site->name . " dump.\n";
            exec("drush -y rsync --remove-source-files @" . $site->name . ":/tmp/dr-backups/" . $site->name . ".tar.gz " . $destination . $site->name . "/ ");
            $list_of_files[] = $site->name . ".tar.gz";
            $routes = str_replace(['https://', 'http://', ','], ['', '', ' '], $site->routes);
            $sites_file_content .= $site->name . " " . $site->name . ".tar.gz " . $destination . $site->name . "/" . $site->name . ".tar.gz 0 \"" . $routes . "\"\n";
            $total = time() - $start;
            $total_time = time() - $start_time;
            print "\n" . $site->name . " took " . $total . " seconds out of total " . $total_time . " seconds.";
            print "\n***************************\n";
        }
        fwrite($sites_file, $sites_file_content);
        copy($destination . "sites.txt", "/home/svc_fin_gcms_p_mgt/.drush/sites.txt");
        //sleep(120); // @TODO: what is this for?


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

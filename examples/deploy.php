<?php
/**
 * @file
 * Deploy a new code reference to the target environment.
 *
 * Usage: php deploy.php dev tags/2.7.0-beta.1-build
 * Usage: php deploy.php test master-build
 * Usage: php deploy.php test master-build 2
 */

declare(strict_types = 1);

use swichers\Acsf\Client\Endpoints\Entity\EntityInterface;
use swichers\Acsf\Client\ServiceLoader;

require 'config.php';
require '../vendor/autoload.php';

// The environment to deploy to.
define('TARGET_ENV', $argv[1] ?? '');
// The code reference to deploy to the target environment.
define('DEPLOY_REF', $argv[2] ?? '');
// The ACSF stack to target.
define('STACK_ID', $argv[3] ?? 1);

if (empty(TARGET_ENV) || empty(DEPLOY_REF)) {
  echo "Must supply a target environment and code reference.\n\n";
  printf(
    "Example: php %s dev tags/2.4.2-build\n",
    basename(__FILE__)
  );
  die(1);
}

$start_time = new DateTime();

$base_config = [
  'username' => API_USERNAME,
  'api_key' => API_KEY,
  'site_group' => ACSF_SITE_GROUP,
  'environment' => TARGET_ENV,
];

$client = ServiceLoader::buildFromConfig(
  ['acsf.client.connection' => $base_config]
)->get('acsf.client');

$refs = $client->getAction('Vcs')->list(['stack_id' => STACK_ID]);
if (!in_array(DEPLOY_REF, $refs['available'])) {
  printf("Unable to find %s in list of available refs.\n", DEPLOY_REF);
  die(1);
}

printf("Current code: %s\n", $refs['current']);
printf("Deploying: %s\n", DEPLOY_REF);

if ('live' == TARGET_ENV) {
  $client->getAction('Sites')->backupAll(
    ['components' => ['database']],
    TRUE,
    30,
    function (EntityInterface $task, $task_status) {

      printf(
        "Backup (%d): %s\n",
        $task->id(),
        $task_status['status_string']
      );
    }
  );

  printf("Backups complete.\n");
}

$task_info = $client->getAction('Update')->updateCode(
  DEPLOY_REF,
  ['stack_id' => STACK_ID]
);
$client->getEntity('Task', intval($task_info['task_id']))->wait(
  30,
  function (EntityInterface $task, array $task_status) {

    printf(
      "Code Deploy (%d): %s\n",
      $task->id(),
      $task_status['status_string']
    );
  }
);

printf("Code deploy completed.\n");

$diff = $start_time->diff(new DateTime());
printf("Script complete. Time elapsed: %s\n", $diff->format('%H:%I:%S'));

exit(0);

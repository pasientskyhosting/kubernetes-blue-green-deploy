#!/usr/bin/env php
<?php
$git_branch = getenv('bamboo_planRepository_branch');
$bamboo_build_nr = getenv('bamboo_buildNumber');
$bamboo_nexus_server = getenv('bamboo_nexus_server');
$bamboo_deployment_environment = getenv('bamboo_deploy_environment');
$bamboo_nexus_server_docker_port = getenv('bamboo_nexus_server_docker_port');
$bamboo_CONSUL_ENVIRONMENT = getenv('bamboo_CONSUL_ENVIRONMENT');
$k8s_build_id = getenv('k8s_build_id');
$serviceDefinitionFile = '../docker/serviceDefinition.json';

if (!file_exists($serviceDefinitionFile)) {
    fwrite(STDERR, "No serviceDefinition.json json found.\n");
    exit(1);
}

/**
* Check if namespace is present
*/
fwrite(STDERR, "Check if environment exists before starting deployment\n");
exec("kubectl get namespace $bamboo_CONSUL_ENVIRONMENT", $array, $exitCode);
if ($exitCode != 0) {
    exec("kubectl create namespace $bamboo_CONSUL_ENVIRONMENT", $array, $exitCode);
    if ($exitCode != 0) {
        fwrite(STDERR, "Failed to created namespace $bamboo_CONSUL_ENVIRONMENT in cluster\n");
        exit(1);
    }

    fwrite(STDERR, "Created namespace $bamboo_CONSUL_ENVIRONMENT in cluster\n");
}

/** Start individual deployments */
$buildConfig = json_decode(file_get_contents($serviceDefinitionFile), true);
$services = $buildConfig['services'];

foreach ($services as $service) {
    $name = $service['name'];

    fwrite(STDERR, "Going to deploy " . $name . PHP_EOL);

    deploy_service($name, $k8s_build_id, $bamboo_CONSUL_ENVIRONMENT);
}


/*
    Deploy service function
*/
function deploy_service($name, $build_id, $namespace)
{
    $method="apply";

    exec("kubectl get service $name-$build_id --namespace=$namespace", $array, $exitCode);
    if ($exitCode != 0) {
        $method="replace";
        fwrite(STDERR, "Service for application already exists for " . $name . PHP_EOL);
    }

    exec("kubectl $method -f service.yaml", $array, $exitCode);
    if ($exitCode != 0) {
        fwrite(STDERR, "Service could not be deployed for " . $name . PHP_EOL);
        return false;
    }

    return true;
}

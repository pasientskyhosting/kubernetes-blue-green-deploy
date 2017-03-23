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
$buildConfig = json_decode(file_get_contents($serviceDefinitionFile), true);
$services = $buildConfig['services'];
$application = $buildConfig['application'];
$current_build_id = null;

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

/**
* Check for nexus key
*/
fwrite(STDERR, "Check if secret to nexus exists before starting deployment\n");
exec("kubectl get secret -n $bamboo_CONSUL_ENVIRONMENT nexus", $array, $exitCode);
if ($exitCode != 0) {
    exec("kubectl create secret docker-registry nexus --docker-username=docker --docker-password=docker --docker-email=jsp@patientsky.com --namespace=$bamboo_CONSUL_ENVIRONMENT --docker-server=https://odn1-nexus-docker.privatedns.zone", $array, $exitCode);
    if ($exitCode != 0) {
        fwrite(STDERR, "Failed to created nexus secret to $bamboo_CONSUL_ENVIRONMENT in cluster\n");
        exit(1);
    }

    fwrite(STDERR, "Created nexus secret for $bamboo_CONSUL_ENVIRONMENT in cluster\n");
}

/** INGRESS **/
if (!deploy_ingress())
{
    cleanup();
}

/** Check for generic service. Add if they are not present */
$has_generic_services = deploy_generic_service();

/** GET CURRENT DEPLOYMENT **/
foreach ($services as $service) {
    $service_dpl_name = "$application-" . $service['name'];
    $tmp_build_id = exec("kubectl get service $service_dpl_name -o yaml --namespace=$bamboo_CONSUL_ENVIRONMENT | grep 'build:' | cut -d ':' -f 2 | tr -d ' ' | tr -d '\"'");

    if (strlen($tmp_build_id) > 0)
    {
        $current_build_id = $tmp_build_id;
        fwrite(STDERR, "Current deployment running is: $current_build_id\n");
        break;
    }
}

/** SERVICES **/
if (!deploy_service())
{
    cleanup();
}

/** HPA **/
if (!deploy_hpa())
{
    cleanup();
}

/** DEPLOYMENTS **/
if (!deploy_deployments())
{
    cleanup();
}

/** Validate that containers started **/


/** Switch to new deployment or cleanup **/
foreach ($services as $service) {
    $service_dpl_name = "$application-" . $service['name'];

    $cmd = 'kubectl get service '. $service_dpl_name.' -o yaml --namespace=' . $bamboo_CONSUL_ENVIRONMENT .' | sed "s/build:.*$/build: \"'. $k8s_build_id. '\"/" | kubectl replace -f -;';
    exec($cmd, $array, $exitCode);
    if ($exitCode != 0) {
        fwrite(STDERR, "Could not switch deployment for $service_dpl_name" . PHP_EOL);
    }
}

/** Delete old deployments **/
cleanup_old_deployment($k8s_build_id,$bamboo_CONSUL_ENVIRONMENT,$services,$application);





/* --------------------------------------------------- */

function cleanup()
{
    fwrite(STDERR, "Deployments failed. Cleaning up after me " . PHP_EOL);
    exec("kubectl delete -f ingress.yaml");
    exec("kubectl delete -f service.yaml");
    exec("kubectl delete -f autoscaler.yaml");
    exec("kubectl delete -f deploy.yaml");
}

function cleanup_old_deployment($build_id, $namespace,$services,$application)
{
    foreach ($services as $service) {
        $current_release = $application . '-' . $service['name'] . '-' . $build_id;

        exec("kubectl delete service $current_release --namespace=$namespace");
        exec("kubectl delete hpa $current_release --namespace=$namespace");
        exec("kubectl delete deployment $current_release --namespace=$namespace");
        exec("kubectl delete rs $current_release --namespace=$namespace");
    }
}

/*
    Deploy ingress function
*/
function deploy_ingress()
{
    exec("kubectl apply -f ingress.yaml", $array, $exitCode);
    if ($exitCode != 0) {
        fwrite(STDERR, "Ingress could not be deployed " . PHP_EOL);
        return false;
    }

    return true;
}


/*
    Deploy service function
*/
function deploy_service()
{
    exec("kubectl apply -f service.yaml", $array, $exitCode);
    if ($exitCode != 0) {
        fwrite(STDERR, "Service could not be deployed " . PHP_EOL);
        return false;
    }

    return true;
}

/*
    Deploy service function
*/
function deploy_generic_service()
{
    exec("kubectl apply -f service-generic.yaml > /dev/null 2>&1", $array, $exitCode);
    if ($exitCode != 0) {
        fwrite(STDERR, "Generic service could not be deployed " . PHP_EOL);
        return false;
    }

    return true;
}


/*
    Deploy hpa function
*/
function deploy_hpa()
{
    exec("kubectl apply -f autoscaler.yaml", $array, $exitCode);
    if ($exitCode != 0) {
        fwrite(STDERR, "HPA could not be deployed " . PHP_EOL);
        return false;
    }

    return true;
}

/*
    Deploy deployments function
*/
function deploy_deployments()
{
    exec("kubectl apply -f deploy.yaml", $array, $exitCode);
    if ($exitCode != 0) {
        fwrite(STDERR, "Deployments could not be deployed " . PHP_EOL);
        return false;
    }

    return true;
}
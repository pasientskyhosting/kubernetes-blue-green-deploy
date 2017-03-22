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



/** INGRESS **/
if (!deploy_ingress())
{
    cleanup();
}

/** SERVICES **/
if (!deploy_service())
{
    cleanup();
}

/** GET CURRENT DEPLOYMENT **/


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

/* --------------------------------------------------- */
function cleanup()
{
    exec("kubectl delete -f ingress.yaml");
    exec("kubectl delete -f service.yaml");
    exec("kubectl delete -f hpa.yaml");
    exec("kubectl delete -f deploy.yaml");
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

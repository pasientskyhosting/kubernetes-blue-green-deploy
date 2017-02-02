# kubernetes-blue-green-deploy
Blue Green deployment script

This project in integrated into our Bamboo environment where we push code to bitbucket first. Then we do a blue green deployment on K8s

#Sample script 
This script is from Bamboo that is run in the deploment process. It needs to get it's parameters from Consul. Should be fairly easy to translate them.

```
#!/bin/bash

# Clone the deployment code
if [ -d kubernetes-blue-green-deploy ]; then
    rm -rf kubernetes-blue-green-deploy
fi
git clone https://github.com/pasientskyhosting/kubernetes-blue-green-deploy.git || exit 1

# Get into directory
cd kubernetes-blue-green-deploy

# Cleanup config
rm config

# Fetch configuration
ssh_key=$(curl -skLu ${bamboo_CONSUL_USERNAME}:${bamboo_CONSUL_PASSWORD} ${bamboo_CONSUL_URL}/v1/kv/${bamboo_CONSUL_ENVIRONMENT}/${bamboo_CONSUL_APPLICATION}/bamboo/deployment_ssh_key?raw)
git_repo=$(curl -skLu ${bamboo_CONSUL_USERNAME}:${bamboo_CONSUL_PASSWORD} ${bamboo_CONSUL_URL}/v1/kv/${bamboo_CONSUL_ENVIRONMENT}/${bamboo_CONSUL_APPLICATION}/bamboo/git_url?raw)
image=$(curl -skLu ${bamboo_CONSUL_USERNAME}:${bamboo_CONSUL_PASSWORD} ${bamboo_CONSUL_URL}/v1/kv/${bamboo_CONSUL_ENVIRONMENT}/${bamboo_CONSUL_APPLICATION}/bamboo/docker_image?raw)
hostname=$(curl -skLu ${bamboo_CONSUL_USERNAME}:${bamboo_CONSUL_PASSWORD} ${bamboo_CONSUL_URL}/v1/kv/${bamboo_CONSUL_ENVIRONMENT}/${bamboo_CONSUL_APPLICATION}/bamboo/aws_hostname?raw)
hostname_ip=$(curl -skLu ${bamboo_CONSUL_USERNAME}:${bamboo_CONSUL_PASSWORD} ${bamboo_CONSUL_URL}/v1/kv/${bamboo_CONSUL_ENVIRONMENT}/${bamboo_CONSUL_APPLICATION}/bamboo/aws_hostname_ip?raw)
zoneid=$(curl -skLu ${bamboo_CONSUL_USERNAME}:${bamboo_CONSUL_PASSWORD} ${bamboo_CONSUL_URL}/v1/kv/${bamboo_CONSUL_ENVIRONMENT}/${bamboo_CONSUL_APPLICATION}/bamboo/aws_zoneid?raw)

# validate vars before we deploy
if [ "$ssh_key" == "" ];
then
    echo "Missing deployment ssh key"  1>&2
    exit 1
fi

if [ "$git_repo" == "" ];
then
    echo "Missing git repo to deploy from"  1>&2
    exit 1
fi

if [ "$bamboo_CONSUL_APPLICATION" == "" ];
then
    echo "Missing application name. What is this?"  1>&2
    exit 1
fi

if [ "$image" == "" ];
then
    echo "Missing docker image to use?"  1>&2
    exit 1
fi

if [ "$hostname" == "" ];
then
    echo "Missing hostname that should respond to this deployment"  1>&2
    exit 1
fi

if [ "$bamboo_CONSUL_ENVIRONMENT" == "" ];
then
    echo "Missing the namespace this belongs to"  1>&2
    exit 1
fi

do_aws_dns=0
if [ "$zoneid" != "" ];
then
    if [ "$hostname_ip" != "" ];
    then
        do_aws_dns=1
    fi
fi

if [ ! -f ../version.commit ]; then
    echo "Missing version.commit file" 1>&2
    exit 1
fi

# Write config
echo "ssh_key=\"$ssh_key\"" > config
echo "build_id=\"`cat ../version.commit`\"" >> config
echo "git_repo=\"$git_repo\"" >> config
echo "application_name=\"$bamboo_CONSUL_APPLICATION\"" >> config
echo "image=\"$image\"" >> config
echo "hostname=\"$hostname\"" >> config
echo "namespace=\"$bamboo_CONSUL_ENVIRONMENT\"" >> config
echo "zoneid=\"$zoneid\"" >> config
echo "hostname_ip=\"$hostname_ip\"" >> config

if [ $do_aws_dns -eq 1 ]; then
    ./aws-dns || exit 1
fi

# Create service
./create || exit 1

# Deploy to kubernetes
./deploy || exit 1
```

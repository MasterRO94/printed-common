# Printed common

Composer package that contains common functionality that can be used in any of the printed.com
projects.

The code in this package must be runnable on PHP5.

## How to unit test

The challenge with unit testing this locally is the fact that our cpdf binary runs only on linux x64.

Ideally, a docker-compose.yml with a php Dockerfile is committed to this repo.

For now, if you need to unit test this, do the following:

### Poor man's unit testing

1. Get a hold of a docker machine. If you have any of the pdc v2 project checked out, you can abuse one
of them.
2. Run: docker-machine start the-machine-name
3. Run: eval $(docker-machine env the-machine-name)
4. Run: docker run -it --name pdc_common_php --rm -v "$(pwd):$(pwd)" php:7.1 /bin/bash
5. Run: apt-get update && apt-get install -y ghostscript imagemagick
6. Run on your host machine (if you haven't already): composer install
7. Cd to the mounted directory (it's the same as on your host machine)
8. Run: vendor/bin/phpunit

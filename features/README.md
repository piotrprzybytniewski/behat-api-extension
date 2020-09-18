# Behat tests

For these tests to pass a HTTP server must be set up to listen on `http://localhost:8080`. 

In the project root directory, run the following command to run PHP built in web server for this purpose:

    composer dev --timeout=0

and to execute Behat tests run:

    composer test:behat

or

    ./vendor/bin/behat --strict

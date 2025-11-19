# ./wpf

This is a cli toolkit for helping the development process with many handy features. To know more about this tool, just run `./wpf` from your plugin's root directory and check the available commands that you may use to ease your development process.

# But, at first!

- Run `chmod 700 ./wpf` to grant the permission and then `./wpf init` to install the dependencies.

If you've done everything right then, you may run `./wpf` to check the list of available commands.

# Test Setup:

- Run `chmod 700 ./test/setup.sh` to grant the necessary permission to run.
- Run `./test/setup.sh dbname dbuser dbpass dbhost` to setup the test suite.

The `dbname` will be used to create the database for testing, so provide your `mysql` username and password in the place of `dbuser` and `dbpass` and use `localhost` for the `dbhost`. Once you complete setting up the test environment, find ther `./stubs/Models/Model.php` and rename the `WPFluent` using the correct namespace of your project from `\WPFluent\App\Models\Model`. If
you did everything correctly then you should be able to write and run tests.

- To check, run `./wpf test` from the root of your plugin directory.

**Note** You may run the `./setup.sh` multiple times if you need to.

# If anything goes wrong:

- `cd /var/folders/hl/9mtnq0xx42n17zs18wwpfwv80000gn/T`
- `rm -rf wordpress`
- `rm -rf wordpress-tests-lib`
- run the `setup.sh` again.
- run `phpunit` again.

**Note:** The path is dynamic so it could be a little bit different. In that case adjust the path from the error message displayed on the console which will mention the location using something similar to this kind of path.

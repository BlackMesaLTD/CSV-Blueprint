# Contributing

If you have any ideas or suggestions, feel free to open an issue or create a pull request on
[this fork](https://github.com/BlackMesaLTD/CSV-Blueprint). For security issues, see [SECURITY.md](SECURITY.md).

```sh
# Fork the repo and build project
git clone git@github.com:BlackMesaLTD/CSV-Blueprint.git ./csv-blueprint
cd ./csv-blueprint
make build

# Make your local changes

# Autofix code style 
make test-phpcsfixer-fix test-phpcs

# Run all tests and check code style
make test
make codestyle

# Create your pull request and check all tests in CI (Github Actions)
# ???
# Profit!
```

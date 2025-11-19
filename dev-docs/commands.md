#### Database Related Commands (Dev Only)

To set up wp cli, Run the following commands (For: macOS)

```sh
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

php wp-cli.phar --info

chmod +x wp-cli.phar

sudo mv wp-cli.phar /usr/local/bin/wp
```

After That Restart Terminal

Drop Existing Database And Run New Migration

```sh
wp fluent_cart migrate_fresh
wp fluent_cart migrate_fresh --seed #default=1000
wp fluent_cart migrate_fresh --seed --count='100'
```
Seeder

```sh
wp fluent_cart seed_all #default=1000
wp fluent_cart seed_all --count='100'
wp fluent_cart seed_products #default=1000
wp fluent_cart seed_products --count='100'
wp fluent_cart seed_product_details #default=1000
wp fluent_cart seed_product_details --count='100'
```

### Also, you can seed a single entity
```shell
  wp fluent_cart seed --customer --count='100'
```

### Or multiple entity like

```shell
wp fluent_cart seed --customer --product --count='100'
```

##### Theme Config

```shell
wp fluent_cart validate_theme_configs
```


## NPM commands


#### Build Commands


Add missing translation for admin dashboard into file app/Services/Translations/admin-translation.php
```shell
npm run translate:admin 
```

```shell
npm run translate:admin --debug
```

also we can use --debug flag to add the source 
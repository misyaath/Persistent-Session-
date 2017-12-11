# Persistent-Session-
Simple PHP class for Persistent Session with Mysql database

Before you add repository in your code add database table in your database 


```mysql
CREATE TABLE IF NOT EXISTS `sessions` (
  `sid` varchar(40) NOT NULL PRIMARY KEY,
  `expiry` int(10) unsigned NOT NULL,
  `data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

Then add your Database username , password and database name in Helper_Session class


```php
/**
     * @var string Default host for database
     */
    protected $host = 'host:port';

    /**
     * @var string Default db_name for database
     */
    protected $db_name = 'db_name';
    /**
     * @var string usernane for database
     */
    protected $db_username = "username";
    /**
     * @var string Default password for database
     */
    protected $db_password = 'password';
    
```
    
Then include config.php file on to your code 


I did simple persistent session storage model calss. If you want to add more data in to database you able change code and queries add more data 

IF you want extra logics edit MysqlSessionHandler 

    
    

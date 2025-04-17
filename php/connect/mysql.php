<?php

class Mysql{
  var $setting = [];
  var $database_name = null;
  var $dbh = null;
  private $columns = [];

  function __construct($server_data=[], $database_name=null){
    if(!is_array($server_data)){return;}
    $this->setting = $server_data;
    $this->database_name = $database_name;
    $this->dbh = $this->pdo_open();
  }

  private function get_dsn(){
    $arr = [];

    // host
    if(@$this->setting["tunnel_host"]){
      array_push($arr, "mysql:host={$this->setting["tunnel_host"]}");
    }
    else if(@$this->setting["dbhost"]){
      array_push($arr, "mysql:host={$this->setting['dbhost']}");
    }

    // port
    if(@$this->setting["tunnel_port"]){
      array_push($arr , "port={$this->setting["tunnel_port"]}");
    }
    else if(@$this->setting['port']){
      array_push($arr , "port={$this->setting['port']}");
    }

    // dbname
    if($this->database_name){
      array_push($arr , "dbname={$this->database_name}");
    }
    
    // charset
    $charset = $this->setting['charset'] ? $this->setting['charset'] : 'utf8mb4';
    $charset = strtolower($charset) === "utl-8" ? "utf8mb4" : $charset;
    array_push($arr , "charset={$charset}");

    return implode(';', $arr);
  }

  private function pdo_open(){
    try{
      $dsn  = $this->get_dsn();
      $user = $this->setting['dbuser'];
      $pass = $this->setting['dbpass'];
      $dbh = new \PDO($dsn, $user, $pass);
      $dbh->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
      $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    catch(PDOException $e){
      $dbh = null;
      die($e->getMessage());
    }
    return $dbh;
  }

  function pdo_close(){
    $this->dbh = null;
  }

  /**
   * MysqlのDatabase一覧の読み込み
   */
  function get_databases($narrow_down=null){
    if($narrow_down){
      return $this->query("show databases like '%{$narrow_down}%'");
    }
    else{
      return $this->query("show databases");
    }
  }

  /**
   * MysqlのTable一覧の読み込み
   */
  function get_tables($database_name=null, $narrow_down = null, $limit=100, $page_num=0){
    if(!$database_name){return;}
    $offset = $page_num ? $page_num * $limit : null;
    $replacement_string = "___SELECT___";
    $query = <<<SQL
SELECT {$replacement_string}
FROM information_schema.tables 
WHERE TABLE_SCHEMA = "{$database_name}"
ORDER BY TABLE_NAME
SQL;
    if($narrow_down){
      $query .= " AND TABLE_NAME LIKE '%{$narrow_down}%'";
    }
    
    // Lists
    $query_lists = str_replace($replacement_string, "TABLE_NAME as name", $query);
    if($limit){
      $query_lists .= PHP_EOL."limit {$limit}";
    }
    if($offset){
      $query_lists .= PHP_EOL."offset {$offset}";
    }
    $query_lists = "-- table-lists".PHP_EOL. $query_lists;

    // count
    $query_count = str_replace($replacement_string, "count(*) as count", $query);
    $query_count = "-- table-count".PHP_EOL. $query_count;
    $res_count   = $this->query($query_count);

    return [
      "lists" => $this->query($query_lists),
      "count" => $res_count ? $res_count[0]["count"] : 0,
      "sql" => [
        $query_lists,
        $query_count,
      ]
    ];
  }

  /**
   * Table column
   */
  function get_column($database_name=null, $table_name=null){
    if(!$database_name || !$table_name){return;}
    $query = "SHOW columns FROM `{$database_name}`.{$table_name}";
    return [
      "datas" => $this->query($query),
      "sql"   => $query,
    ];
  }


  /**
   * Query
   */
  function query($query_string=""){
    if(!$query_string){return null;}
    try{
      $res = $this->dbh->query($query_string);
    }
    catch(Exception $e){
      echo $query_string . PHP_EOL;
      // die($e->getMessage());
      $res = null;
    }
    return $res;
    // $datas = [];
    // try {
    //   // if(is_iterable($res)){ // php7.1以降で対応
    //   // if($res){ // falseを扱えない場合あり php5.4の場合
    //   if ($res instanceof PDOStatement) {
    //     foreach ($res as $val){
    //       $datas[] = $val;
    //     }
    //   }
    // }
    // catch(Exception $e){
    //   die($e->getMessage());
    // }
    // return $datas;
  }

  function insert($table="", $hashes=[], $timeout=null){
    if(!$table || !$hashes){return;}
    $timeout = $timeout ? $timeout : 1000;
    
    $keys  = array_keys($hashes);
    $keys1 = implode(",", array_keys($hashes));
    $keys2 = implode(",", array_map(function($key){return ":{$key}";} , $keys));
    $query = <<<SQL
INSERT INTO `{$this->database_name}`.{$table}
($keys1)
VALUES
($keys2)
SQL;

    $values = $this->conv_values($table, $hashes);

    try{
      $res = null;
      $last_id = null;
      $this->dbh->beginTransaction();
      $pre = $this->dbh->prepare($query);
      $exe = $pre->execute($values);
      if($exe){
        $last_id = $this->dbh->lastInsertId();
        $this->dbh->commit();
        if($last_id){
          $res = $this->query("SELECT * FROM `{$this->database_name}`.{$table} WHERE id={$last_id}");
        }
        // else{
        //   $res = null;
        // }
      }
    }
    catch(Exception $e){
      $this->dbh->rollBack();
    }

    return [
      // "data"  => $res ? $res[0] : null,
      "datas" => $res,
      "sql"   => $query,
      "latest_id" => $last_id,
    ];
  }

  function update($table="", $hashes=[], $where=null, $timeout=null){
    if(!isset($hashes["update_at"])){
      $hashes["update_at"] = date("Y-m-d H:i:s");
    }
    if(!$table || !$hashes || !$where){return;}
    $timeout = $timeout ? $timeout : 1000;

    $keys = array_keys($hashes);
    $set_queries = implode(",", array_map(function($key){return "{$key} = :{$key}";} , $keys));
    $query = <<<__SQL__
UPDATE `{$this->database_name}`.{$table}
SET {$set_queries}
WHERE {$where}
__SQL__;

    $values = $this->conv_values($table, $hashes);

    $search_where = $where ? "WHERE {$where}" : "";
    $search_query = "SELECT * FROM `{$this->database_name}`.{$table} {$search_where}";

    $res = null;
    try{
      $this->dbh->beginTransaction();
      $pre = $this->dbh->prepare($query);
      $exe = $pre->execute($values);
      if($exe){
        $this->dbh->commit();
        $res = $this->query($search_query);
      }
    }
    catch(Exception $e){
      $this->dbh->rollBack();
    }

    return [
      "datas"  => $res ? $res : null,
      "sql"    => $query,
      "select" => $search_query,
    ];
  }

  function delete($table="", $where=null, $timeout=null){
    if(!$table || !$where){return;}
    $timeout = $timeout ? $timeout : 1000;

    $query = <<<__SQL__
DELETE FROM `{$this->database_name}`.{$table}
WHERE {$where}
__SQL__;

    try{
      $this->dbh->beginTransaction();
      $pre = $this->dbh->prepare($query);
      $pre->execute();
      $this->dbh->commit();
    }
    catch(Exception $e){
      $this->dbh->rollBack();
    }

    return [
      "sql"   => [$query],
    ];
  }

  function conv_values($table_name=null, $values=[]){
    foreach($values as $key => $val){
      $values[$key] = $this->conv_value($table_name, $key, $val);
    }
    return $values;
  }
  function conv_value($table_name=null, $column_name=null, $value=null){
    $type = null;
    if(isset($this->columns[$column_name])){
      $type = $this->columns[$column_name];
    }
    else{
      $query = "SHOW columns FROM {$table_name} WHERE Field='{$column_name}'";
      $res   = $this->query($query);
      $type  = $res ? strtolower($res[0]["Type"]) : null;
    }
    if($res){
      switch (true){
        // 整数
        case preg_match('/^int/i', $type):
          if($value === "" || $value === "null"){
            return null;
          }
          else{
            return (int)$value;
          }

        // 浮動小数
        case preg_match('/^float|^double|^decimal|^numeric/i', $type):
          if($value === "" || $value === "null"){
            return null;
          }
          else{
            return (float)$value;
          }
    
        // 文字列
        case preg_match('/^varchar|^char|^text|^tinytext|^mediumtext|^longtext/i', $type):
          return (string)$value ?: null;
    
        // 日付/時間型
        case preg_match('/^date|^datetime|^timestamp|^time|^year/i', $type):
          return $value ?: null;
    
        case preg_match('/^tinyint\(1\)/i', $type):
          return $value ? true : false;
    
        case preg_match('/^blob|^tinyblob|^mediumblob|^longblob/i', $type):
          return $value;
    
        default:
          return $value;
      }
    }
  }

}
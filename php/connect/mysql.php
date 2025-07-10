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
SQL;
    if($narrow_down){
      $query .= " AND TABLE_NAME LIKE '%{$narrow_down}%'";
    }

    $query .= "\nORDER BY TABLE_NAME";
    
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
    $datas = [];
    try {
      // if(is_iterable($res)){ // php7.1以降で対応
      if($res && $res !== false){ // falseを扱えない場合あり php5.4の場合
      // if ($res instanceof PDOStatement) {
        foreach ($res as $val){
          $datas[] = $val;
        }
      }
    }
    catch(Exception $e){
      die($e->getMessage());
    }
    return $datas;
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

  // 大量insertの場合に、まとめて登録できる処理
  // ex) $datas = [[id=>1,name="foo",memo="bar"],[id=>2,name="foo",memo="bar"]]
  function insert_bulk(string $table = "", array $datas = [], int $chunkSize = 0, $timeout=null){
    try{
      if(!$table || !$datas){return;}
      $this->dbh->beginTransaction(); // トランザクション開始
      // $timeout = $timeout ? $timeout : 1000;
      $total = count($datas);
      $res = [];
      $sql_arr = [];
      $latest_id = null;
      $chunkSize = $chunkSize ? $chunkSize : count($datas); // 一度に挿入するレコード数（デフォルトは全件挿入）

      for ($i = 0; $i < $total; $i += $chunkSize) {
        $chunk = array_slice($datas, $i, $chunkSize);
        if (empty($chunk)) continue;

        $placeholders = [];
        $values = [];
        $columns = array_keys($chunk[0]);

        foreach ($chunk as $row) {
          $placeholders[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
          foreach ($columns as $col) {
            if($row[$col] === ''){$row[$col] = null;}
            $values[] = $row[$col];
          }
        }

        $sql = "INSERT INTO {$table} (" . implode(',', $columns) . ") VALUES " . implode(', ', $placeholders);
        $sql_arr[] = $sql;
        $stmt = $this->dbh->prepare($sql);
        $res[] = $stmt->execute($values);

        if ($latest_id === null) {
          $latest_id = $this->dbh->lastInsertId(); // 最初のIDだけ保存
        }

        // 負荷軽減のために少し待機（例：0.1秒）
        usleep(100000);
      }
      $this->dbh->commit();
    }

    catch(Exception $e){
      if ($this->dbh->inTransaction()) {
        $this->dbh->rollBack();
      }
      error_log("insert_bulk error: " . $e->getMessage());
      // return false; // エラー時は false を返すなども有効
    }

    return [
      "datas" => $res ? $datas : [],
      "sql"   => $sql_arr,
      "latest_id" => $this->dbh->lastInsertId(),
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
      switch (true) {
        // boolean (tinyint(1))
        case preg_match('/^tinyint\(1\)$/i', $type):
          if ($value !== 0 && $value !== 1) {
            throw new InvalidArgumentException('Invalid boolean value');
          }
          return (int)$value === 1;
      
        // 整数（int, tinyint, smallint, mediumint, bigint）
        case preg_match('/^(int|tinyint|smallint|mediumint|int|bigint)(\(\d+?\))?/i', $type):
          // return ($value === "" || $value === "null" || $value === null) ? null : (int)$value;
          if ($value === "" || $value === "null" || $value === null) {
            return null; // 空文字列やnullはそのままnullとして扱う
          }
          if (!is_numeric($value)) {
              throw new InvalidArgumentException("Invalid integer value: $value");
          }
          return (int)$value;
      
        // 浮動小数（float, double, decimal, numeric）
        case preg_match('/^(float|double|decimal|numeric)(\(\d+,\d+\))?/i', $type):
          return ($value === "" || $value === "null" || $value === null) ? null : (float)$value;
      
        // 文字列
        case preg_match('/^(varchar|char|text|tinytext|mediumtext|longtext)/i', $type):
          return ($value === "" || $value === "null" || $value === null) ? null : (string)$value;
      
        // 日付・時間
        case preg_match('/^(date|datetime|timestamp|time|year)/i', $type):
          return ($value === "" || $value === "null" || $value === null) ? null : $value;
      
        // BLOB
        case preg_match('/^(blob|tinyblob|mediumblob|longblob)/i', $type):
          return $value;
      
        default:
          return $value;
      }
    }
  }


  /**
   * 複数テーブルのトランザクション対応
   * - insert,update判定も行う。
   * - バルク処理は行わないので、大量登録の場合は、個別テーブルで行うか独自に処理を書くようにする。
   * 
   * [param]
   * @$table_datas : 複数テーブルの状態を記載
   * [
   *   $name  : string : テーブル名
   *   $data  : $object : カラム毎のテーブルデータ（連想配列データ）
   *   $where : 書かれている場合、update処理を行うが、where結果が0の場合は、insertを行う。
   * ], ...
   */
  function multi_transaction(array $table_datas=[], $timeout=null){
    $timeout = $timeout ? $timeout : 1000;
    $res_arr = [];
    $sql_arr = [];
    $last_id_arr = [];

    if (!$this->dbh) {
      throw new Exception('Database connection is not initialized.');
    }

    // データが空の場合、または配列じゃ無い場合は処理しない。
    if($table_datas && count($table_datas)){
      try{
        $this->dbh->beginTransaction();
        
        for($i=0; $i<count($table_datas); $i++){

          // テーブル毎の送り値整理
          $table_name = $table_datas[$i]["name"]  ?? null;
          $table_data = $table_datas[$i]["data"]  ?? null;
          $where      = $table_datas[$i]["where"] ?? null;
          if(!$table_name || !$table_data){continue;}

          if($where){
            $table_data["update_at"] = date("Y-m-d H:i:s");
          }
          $keys  = array_keys($table_data);

          // update
          if($where){
            $set_queries = implode(",", array_map(function($key){return "{$key} = :{$key}";} , $keys));
            $query = <<<__SQL__
UPDATE `{$this->database_name}`.{$table_name}
SET {$set_queries}
WHERE {$where}
__SQL__;
            $values = $this->conv_values($table_name, $table_data);
            $pre    = $this->dbh->prepare($query);
            $exe    = $pre->execute($values);
            if($exe){
              $search_query = "SELECT * FROM `{$this->database_name}`.{$table_name} WHERE {$where}";
              $res_arr[$table_name] = $this->query($search_query);
            }
          }

          // insert
          else{
            $keys1  = implode(",", array_keys($table_data));
            $keys2  = implode(",", array_map(function($key){return ":{$key}";} , $keys));
            $values = $this->conv_values($table_name, $table_data);
            $query  = <<<SQL
INSERT INTO `{$this->database_name}`.{$table_name}
($keys1)
VALUES
($keys2)
SQL;
            $sql_arr[] = $query;
            $pre = $this->dbh->prepare($query);
            $exe = $pre->execute($values);
            if($exe){
              $last_id = $this->dbh->lastInsertId();
              $last_id_arr[$table_name] = $last_id;
              if($last_id){
                $res_arr[$table_name] = $this->query("SELECT * FROM `{$this->database_name}`.{$table_name} WHERE id={$last_id}");
              }
            }
          }
        }
        $this->dbh->commit();
      }
      catch(Exception $e){
        print_r($e->getMessage());
        $this->dbh->rollBack();
        $res_arr = null;
      }
    }
    return [
      "datas" => $res_arr,
      "sql"   => $sql_arr,
      "latest_id" => $last_id_arr,
    ];
  }
}
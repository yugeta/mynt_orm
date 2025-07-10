<?php

class Sqlite{
  var $setting       = [];
  var $dbh           = null;
  var $dir           = null;
  var $database_name = null;

  function __construct($server_data=[], $database_name=null){
    if(!is_array($server_data)){return;}
    $this->dir = __DIR__.'/'. $server_data['dbdir'];
    $this->setting = $server_data;
    $this->database_name = $database_name;
    $this->pdo_open();
  }

  private function pdo_open(){
    if(!is_array($this->setting)){return;}
    if(!$this->database_name){return;}
    $path = $this->dir. $this->database_name;
    if(is_file($path)){
      $this->dbh = new \SQLite3($path);
      return $this->dbh;
    }
  }

  function pdo_close(){
    if($this->dbh){
      $this->dbh->close();
    }
  }

  /**
   * SQLiteのDatabaseファイル一覧の取得（指定フォルダ）
   */
  function get_databases($narrow_down=null){
    if(!is_dir($this->dir)){return;}
    $arr = [];
    $files = scandir($this->dir);
    for($i=0,$c=count($files); $i<$c; $i++){
      if(!is_file($this->dir. $files[$i])){continue;}
      if(!preg_match('/^(.+?)\.(sql|db|sqlite)/', $files[$i])){continue;}
      if($narrow_down && !preg_match('/'. $narrow_down .'/', $files[$i])){continue;}
      array_push($arr , ['Database'=>$files[$i]]);
    }
    return $arr;
  }

  /**
   * SQLiteのTable一覧の読み込み
   */
  function get_tables($database_name=null, $narrow_down = null, $limit=100, $page_num=0){
    $query = <<<SQL
SELECT name FROM sqlite_master WHERE type="table"
SQL;
    $res = $this->query($query);
    // $this->pdo_close();
    return [
      "lists" => $res,
      "count" => count($res),
      "sql"   => [$query],
    ];
  }


  function get_column($database_name=null, $table_name=null){
    if(!$database_name || !$table_name){return;}
    $query = <<<SQL
SELECT * 
FROM sqlite_master 
WHERE type='table' AND name='{$table_name}'
SQL;
    $res = $this->query($query);
    // $this->pdo_close();

    return [
      "datas" => $res && count($res) ? $this->get_column_info($res[0]["sql"]) : [],
      "sql"   => [$query],
    ];
  }

  function get_column_info($schema_sql=null){
    if(!$schema_sql){return [];}
    preg_match('/^CREATE TABLE (.+?)\((.+?)\)$/iums' , trim($schema_sql) , $match);
    if(!$match){return [];}
    $table_name   = $match[1];
    $table_schema = $match[2].')';
    $schema_str   =  $this->convert_comma($table_schema);
    $sp1 = explode("," , $schema_str);
    $new_lists = [];
    $relations = [];
    for($i=0,$c=count($sp1); $i<$c; $i++){
      if(!$sp1[$i]){continue;}
      $str = trim($sp1[$i]);
      $sp2 = explode(' ', $str);
      if(strtoupper($sp2[0]) === 'FOREIGN'){
        preg_match('/key \((.+?)\) references (.+?)\((.+?)\)$/iums' , trim($sp1[$i]) , $relation_match);
        $relations[$relation_match[1]] = [
          'table'  => $relation_match[2],
          'column' => $relation_match[3],
        ];
      }
      else{
        array_push($new_lists , [
          'name' => $sp2[0],
          'type' => $sp2[1],
          'content' => implode(' ', array_slice($sp2 , 2)),
          'memo' => $sp1[$i],
        ]);
      }
    }
    for($i=0,$c=count($new_lists); $i<$c; $i++){
      $name = $new_lists[$i]['name'];
      if(!isset($relations[$name])){continue;}
      $new_lists[$i]['link_table']  = $relations[$name]['table'];
      $new_lists[$i]['link_column'] = $relations[$name]['column'];
    }
    return $new_lists;
  }

  function convert_comma($str=''){
    preg_match_all('/\(.+?\)/iums' , $str , $matches);
    for($i=0,$c=count($matches); $i<$c; $i++){
      $conv_str = str_replace(',' , '&comma;' , $matches[$i]);
      $str = str_replace($matches[$i] , $conv_str , $str);
      $str = str_replace("\r",'', $str);
      $str = str_replace("\n",'', $str);
    }
    return $str;
  }

  /**
   * Query
   */
  // function get_query($query_add_limit=null, $count_query=null, $database_name=null){
  //   $query_datas = $this->query($query_add_limit);
  //   $count_datas = $this->query($count_query);

  //   $data = [
  //     "datas" => $query_datas,
  //     "count" => $count_datas ? $count_datas[0]["count"] : 0,
  //     "sql"   => [$query_add_limit, $count_query],
  //   ];
  //   // $this->pdo_close();
  //   return $data;
  // }

  /**
   * Execution
   */
  
  function query($query_string=""){
    if(!$query_string || !$this->dbh){return null;}
    try{
      if(!$this->dbh){return;}
      try{
        $res = $this->dbh->query($query_string);
      }
      catch(Exception $e){
        echo $query_string.PHP_EOL;
        echo "[Error]";
        echo $e->getMessage();
      }
    }
    catch(Exception $e){
      echo $query_string . PHP_EOL;
      die($e->getMessage());
    }
    $datas = [];
    if($res){
      while ($row = $res->fetchArray(SQLITE3_ASSOC)){
        $datas[] = $row;
      }
    }
    return $datas;
  }


  function insert($table="", $hashes=[], $timeout=null){
    if(!$table || !$hashes){return;}
    $timeout = $timeout ? $timeout : 1000;
    
    try{
      $keys  = array_keys($hashes);
      $keys1 = implode(",", $keys);
      $keys2 = implode(",", array_map(function($key){return ":{$key}";} , $keys));

      $query = <<<SQL
INSERT INTO {$table} ($keys1)
VALUES ($keys2)
SQL;

      $this->dbh->exec("PRAGMA busy_timeout={$timeout}");
      $this->dbh->exec("begin");
      $pre = $this->dbh->prepare($query);
      foreach($hashes as $key => $val){
        $pre->bindValue(":{$key}" , $val);
      }
      $pre->execute();
      $exe = $this->dbh->exec("commit");
    }
    catch(Exception $e){
      $exe = $this->dbh->exec("rollback");
    }

    if($exe){
      $res = $this->query("SELECT max(rowid) as max_id, * FROM {$table}");
    }
    else{
      $res = [];
    }

    return [
      "datas" => $res,
      "query" => $query,
    ];
  }

  function insert_bulk($table="", $hashes=[], $timeout=null){
    return [
      "datas" => null,
      "query" => null,
    ];
  }


  function update($table="", $hashes=[] , $where="", $timeout=null){
    if(!isset($hashes["update_at"])){
      $hashes["update_at"] = date("Y-m-d H:i:s");
    }
    if(!$table || !$hashes || !$where){return;}
    $timeout = $timeout ? $timeout : 1000;

    $keys = array_keys($hashes);
    $set_queries = implode(",", array_map(function($key){return "{$key} = :{$key}";} , $keys));
    $query = <<<__SQL__
UPDATE {$table}
SET {$set_queries}
WHERE {$where}
__SQL__;

    try{
      $this->dbh->exec("PRAGMA busy_timeout={$timeout}");
      $this->dbh->exec("begin");
      $pre = $this->dbh->prepare($query);
      foreach($hashes as $key => $val){
        $pre->bindValue(":{$key}" , $val);
      }
      $pre->execute();
      $exe = $this->dbh->exec("commit");
    }
    catch(Exception $e){
      $exe = $this->dbh->exec("rollback");
    }
    if($exe){
      $res = $this->query("SELECT * FROM {$table} WHERE {$where}");
      // if($where2){
      //   $res = $this->query_run("SELECT * FROM {$table} WHERE {$where2}");
      // }
      // else{
      //   $res = $this->query_run("SELECT * FROM {$table} WHERE {$where}");
      // }
    }
    else{
      $res = [];
    }
    return $res;
  }


  function delete($table="", $where=null, $timeout=null){
    if(!$table || !$where){return;}
    $timeout = $timeout ? $timeout : 1000;
    $res = null;

    $query = <<<SQL
DELETE FROM {$table} 
WHERE {$where}
SQL;
        
    try{
      $this->dbh->exec("PRAGMA busy_timeout={$timeout}");
      $this->dbh->exec("begin");
      $this->dbh->query($query);
      $res = $this->dbh->exec("commit");
    }
    catch(Expection $e){
      $this->dbh->exec("rollback");
    }
    return [
      "data"  => $res,
      "query" => $query,
    ];
  }

   /**
   * 複数テーブルのトランザクション対応
   */
  function multi_table_transaction(array $table_datas=[]){

  }
}
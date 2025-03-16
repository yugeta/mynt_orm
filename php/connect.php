<?php

class Connect{
  function __construct($server_name=null, $database_name=null){
    if(!$server_name){return;}
    require_once(__DIR__."/server_data.php");
    $server_data = new ServerData($server_name);
    // print_r($server_data);exit();
    if(!$server_data || !$server_data->data){return;}
    $this->server_data = $server_data->data;
    $this->database_name = $database_name;
    
    $this->connect();
    $this->sql = $this->get_sql();
  }

  var $server_data   = [];
  var $tunnel        = null;
  var $tunnel_cmd    = null;
  var $tunnel_flg    = null;
  var $options       = [];
  var $sql           = null;
  var $database_name = null;


  /**
   * Database共通操作
   */
  function connect(){
    if(!$this->server_data){return;}
    require_once(__DIR__."/tunnel.php");

    $this->tunnel = new Tunnel($this->server_data);
    $this->tunnel_cmd = $this->tunnel->cmd;
    $this->tunnel_flg = $this->tunnel->flg;
    $this->server_data["tunnel_flg"] = $this->tunnel_flg;
  }

  function disconnect(){
    if($this->sql){
      $this->sql->pdo_close();
    }
  }


  function get_sql(){
    switch($this->server_data["type"]){
      case "mysql":
        require_once(__DIR__."/connect/mysql.php");
        return new Mysql($this->server_data, $this->database_name);
      break;

      case "sqlite":
        require_once(__DIR__."/connect/sqlite.php");
        return new Sqlite($this->server_data, $this->database_name);
      break;

      case "postgre":
        return;
    }
  }


  function get_database_lists($options=[]){
    if(!$this->sql){return;}
    $datas = $this->sql->get_databases(@$options["narrow_down"], @$options["file_name"]);
    // $this->sql->pdo_close();
    return $datas;
  }


  function get_table_lists($options=[]){
    if(!$options["database_name"]){return;}
    $datas = $this->sql->get_tables(
      $options["database_name"], 
      @$options["narrow_down"], 
      @$options["limit"], 
      @$options["page_num"]
    );
    // $this->sql->pdo_close();
    return $datas;
  }


  function get_table_column($options=[]){
    $res = $this->sql->get_column(
      @$options["database_name"], 
      @$options["table_name"]
    );
    // $this->sql->pdo_close();
    return $res;
  }

  /**
   * Query
   */
  function query($query=null){
    return $this->sql->query($query);
  }
  
  function get_query($query=null){
    return $this->sql->query($query);
  }

  function get_query_with_count($options=[]){
    $query_string  = $options["query"];
    $select_flg = $this->is_select_query($query_string);
    if($select_flg){
      $query_string .= $this->set_query_add_limit(@$options["query"], @$options["limit"]);
      $query_string .= $this->set_query_add_offset(@$options["query"], @$options["limit"], @$options["page_num"]);
      $count_query   = "SELECT count(*) as count FROM ({$options["query"]}) AS CNT";
    }
    else{
      $count_query = "";
    }

    $query_datas = $this->sql->query($query_string);
    $count_datas = $select_flg ? $this->sql->query($count_query) : "";

    return [
      "datas"  => $query_datas,
      "count"  => $count_datas ? $count_datas[0]["count"] : 0,
      "sql"    => [$query_string, $count_query],
      "select_flg" => $select_flg,
    ];
    // if($this->is_select_query($query_add_limit)){
    //   $count_query = "SELECT count(*) as count FROM ({$options["query"]}) AS CNT";
    // }

    // $res = $this->sql->get_query_with_count($query_add_limit, $count_query, $options["database_name"]);
    // $this->sql->pdo_close();

    // return $res;
  }

  function set_query_add_limit($query=null, $limit=null){
    if(!$query || !$limit){return "";}
    if(!$this->is_select_query($query)){return "";}
    return !preg_match("/limit( +?)(\d+?)/i" , $query) ? " LIMIT {$limit}" : "";
  }
  function set_query_add_offset($query=null, $limit=null, $page_num=null){
    if(!$query || !$page_num){return "";}
    if(!$this->is_select_query($query)){return "";}
    $offset = $limit * ($page_num -1);
    return !preg_match("/offset( +?)(\d+?)/i" , $query) ? " OFFSET {$offset}" : "";
  }
  function is_select_query($query=null){
    return !preg_match("/^(insert|update|delete)/i", trim($query)) ? true : false;
  }

  /**
   * insert
   */
  function insert($table_name=null, $data=[], $timeout=null){
    return $this->sql->insert($table_name, $data, $timeout);
  }

  /**
   * update
   */
  function update($table_name=null, $data=[], $where=null, $timeout=null){
    return $this->sql->update($table_name, $data, $where, $timeout);
  }

  /**
   * delete
   */
  function delete($table_name=null, $where=null, $timeout=null){
    return $this->sql->delete($table_name, $where, $timeout);
  }
}
<?php

class ServerData{
  function __construct($server_name=null){
    if($server_name){
      $this->data = $this->get_server_data($server_name);
    }
    else{
      $this->datas = $this->load_database_lists();
    }
  }

  var $datas = [];
  var $data  = [];
  // var $path  = __DIR__ ."/../../../data/setting_database.json";
  var $dir   = __DIR__ ."/../data/";
  var $json  = null;

  /**
   * data/databases.json
   */
  
  // データベースの登録一覧を取得
  function load_database_lists(){
    if(!is_dir($this->dir)){return;}
    $lists = scandir($this->dir);
    $datas = [];
    for($i=0; $i<count($lists); $i++){
      if(!preg_match("/(.+?)\.json$/", $lists[$i], $match)){continue;}
      $json    = file_get_contents($this->dir.$lists[$i]);
      $data    = json_decode($json, true);
      $data["name"] = $match[1];
      $datas[] = $data;
    }
    return $datas;
  }

  // database設定一覧から、該当のサーバー情報を取得
  function get_server_data($server_name=null){
    if(!$server_name){return;}
    $path = $this->dir. $server_name .".json";
    if(!is_file($path)){return;}
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    return $data;
    // $server_datas = $this->datas ? $this->datas : $this->load_database_json();
    // if(!$server_datas){return;}
    // $this->data  = $server_datas[array_search($server_name , array_column($server_datas , 'name'))];
  }
}
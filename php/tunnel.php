<?php
/**
 * sshトンネル
 * - トンネル起動をしているかどうかをチェックして起動する。
 * > ssh -gNL 13306:localhost:3306 -i ~/.ssh/mobweb_key wwwuser@10.23.0.30
 * - トンネルポートを停止するコマンド
 * > lsof -i:13306
 * > kill -9 %PID
 */

class Tunnel{
  function __construct($server_data=[]){
    if(!$server_data
    || !is_array($server_data)
    || !isset($server_data["tunnel_port"])
    || !isset($server_data["tunnel_ssh"])){
      return false;
    }

    $this->server_data = $server_data;
    $this->port = $server_data["tunnel_port"];

    if($this->is_connecting() !== true){
      $this->connect();
    }

    if($this->is_connecting() === true){
      $this->flg = 1;
    }
    else{
      $this->flg = 0;
    }
  }


  var $server_data = [];
  var $cmd  = null;
  var $flg  = null;
  var $port = null;
  // var $ssh  = null;


  function is_connecting(){
    if(!$this->port){return null;}
    $cmd = "lsof -i:{$this->port} -t 2>&1";
    exec($cmd , $res);
    return $res && is_array($res) && count($res) ? true : false;
  }
  

  function connect($datas=[]){
    $datas    = $this->server_data;
    $port1    = $datas['port'];
    $port2    = $this->port;
    $host     = $datas["dbhost"];
    $ssh      = $this->server_data["tunnel_ssh"];
    $cmd      = "ssh -f -g -N -C -L {$port2}:{$host}:{$port1} {$ssh}";
    $cmd     .= ">/dev/null 2>&1";
    try{
      $this->cmd = $cmd;
      exec($cmd, $cmd_res, $cmd_ret);
    }
    catch(Exception $e){
      echo "[Error]<br>";
      die($e->getMessage());
    }
  }


  function disconnect(){
    if(!$this->port){return;}
    $cmd = "lsof -i:{$this->port} -t 2>&1";
    exec($cmd , $res);
    if(!$res || !is_array($res) || !count($res)){return;}
    for($i=0; $i<count($res); $i++){
      exec("kill -9 {$res[$i]} 2>&1");
    }
  }
}

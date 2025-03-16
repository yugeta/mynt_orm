<?php

/**
 * システムでアクセスするデータベースの操作処理
 * - page/database/ 内で使用
 * - どこでも、apiとして、plugin/mynt_orm/main.php にアクセスすることで、データ操作が行える。
 */

$start_time = microtime(true);

switch(@$_POST['mode']){

  /**
   * data/setting_database.json
   * アクセスするサーバー情報を取得する。
   * 
   * [send : $_POST]
   * - server_name : ※リスト内のname値
   * ※ インスタンスでserver_nameを送ると、該当のデータのみを返す。
   * ※ 指定がない場合は、一覧リストを返す。
   */
  case "get_server_lists":
    require_once(__DIR__."/php/server_data.php");
    $server_data = new ServerData();
    $data  = $server_data->datas;
    $message = !$data ? "{$server_data->path}が存在しません。" : "";
    $datas = [
      "status"  => $data ? "success" : "error",
      "datas"   => $data,
      "message" => $message,
      "time"    => microtime(true) - $start_time,
    ];
    echo json_encode($datas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  break;


  case 'get_database_lists':
    require_once(__DIR__."/php/connect.php");
    $connect = new Connect(@$_POST["server_name"], @$_POST["database_name"]);
    $datas = $connect->get_database_lists($_POST);
    $data = [
      "status"      => $datas && count($datas) ? "success" : "error",
      "datas"       => $datas,
      "options"     => $_POST,
      "time"        => microtime(true) - $start_time,
    ];
    echo json_encode($data ,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  break;

  
  case 'get_table_lists':
    require_once(__DIR__."/php/connect.php");
    $connect = new Connect(@$_POST["server_name"], @$_POST["database_name"]);
    $datas = $connect->get_table_lists($_POST);
    $data = [
      "status"      => $datas && count(@$datas["lists"]) && @$datas["count"] ? "success" : "error",
      "datas"       => @$datas["lists"],
      "count"       => @$datas["count"],
      "sql"         => @$datas["sql"],
      "options"     => $_POST,
      "time"        => microtime(true) - $start_time,
    ];
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  break;


  case 'get_table_column':
    require_once(__DIR__."/php/connect.php");
    $connect = new Connect(@$_POST["server_name"], @$_POST["database_name"]);
    $res = $connect->get_table_column($_POST);
    $data = [
      "status"      => @$res["datas"] ? "success" : "error",
      "datas"       => @$res["datas"],
      "sql"         => @$res["sql"],
      "options"     => $_POST,
      "time"        => microtime(true) - $start_time,
    ];
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  break;


  case 'get_query':
    require_once(__DIR__."/php/connect.php");
    $connect = new Connect(@$_POST["server_name"], @$_POST["database_name"]);
    $res = $connect->get_query_with_count($_POST);
    $data = [
      "status"      => $res["datas"] ? "success" : "error",
      "datas"       => $res["datas"],
      "count"       => $res["count"],
      "sql"         => $res["sql"],
      "select_flg"  => $res["select_flg"],
      "options"     => $_POST,
      "time"        => microtime(true) - $start_time,
    ];
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  break;

  case 'command':
    require_once(__DIR__."/php/connect.php");
    $connect = new Connect(@$_POST["server_name"], @$_POST["database_name"]);
    $res = $connect->query($_POST["command"]);
    $data = [
      "status"      => $res ? "success" : "error",
      "datas"       => $res,
      "options"     => $_POST,
      "time"        => microtime(true) - $start_time,
    ];
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  break;
}

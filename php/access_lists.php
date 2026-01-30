<?php

class AccessLists{
  public $datas = null;

  public function __construct(){
    $this->datas = $this->get_lists();
  }

  private function get_lists(){
    $lists = [];
    $dir = realpath(__DIR__ . "/../data/");
    $files = scandir($dir);
    foreach($files as $file){
      if(in_array($file, [".", ".."])){continue;}
      $json = file_get_contents($dir ."/". $file);
      $data = json_decode($json, true) ?? [];
      $name = str_replace(".json", "", $file);
      $lists[] = [
        "dir"  => $dir,
        "file" => $file,
        "name" => $name,
        "dbname" => $data["dbname"] ?? null,
        "data" => $data,
      ];
    }
    return $lists;
  }
}
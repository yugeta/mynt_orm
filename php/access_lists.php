<?php

class AccessLists{
  public $datas = null;

  public function __construct(){
    $this->datas = $this->get_lists();
  }

  private function get_lists(){
    $lists = [];
    $dir = __DIR__ . "/../data/";
    $files = scandir($dir);
    foreach($files as $file){
      if(in_array($file, [".", ".."])){continue;}
      $name = str_replace(".json", "", $file);
      $lists[] = [
        "dir"  => $dir,
        "file" => $file,
        "name" => $name,
      ];
    }
    return $lists;
  }
}
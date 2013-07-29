<?php
namespace Wrench\Application;

use Wrench\Application\Application;
use Wrench\Application\NamedApplication;

class Chess extends Application
{
  private $clients = array();
  private $running_games = array();

  public function __construct()
  {
    $this->running_games[] = new GameInstance();
  }

  public function OnConnect($connection)
  {
    $found = false;
    foreach($this->running_games as $key => $game)
    {
      if(!$this->running_games[$key]->is_full())
      {
        $this->running_games[$key]->add_client($connection);
        $this->clients[$connection->getPort()] =& $this->running_games[$key];
        $found = true;
      }
    }

    if(!$found)
    {
      $game = new GameInstance($connection);
      $this->running_games[] =& $game;
      $this->clients[$connection->getPort()] =& $game;
    }
  }

  public function onDisconnect($connection)
  {
    $key = $connection->getPort();
    $this->clients[$key]->on_disconnect($connection);
    unset($this->clients[$key]);
  }

  public function OnData($data, $connection)
  {
    $this->clients[$connection->getPort()]->on_data($data, $connection);
  }

  public function OnUpdate()
  {
    foreach($this->running_games as &$game)
    {
      $game->on_update();
    }
  }

}
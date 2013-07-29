<?php
namespace Wrench\Application;
use Wrench\Client;

class GameInstance
{
  const COLOR_WHITE = "white";
  const COLOR_BLACK = "black";

  private $current_connections = array();
  private $former_map = array();
  private $map = array();
  private $step = 0;
  private $current_turn = self::COLOR_WHITE;
  private $colors = array(self::COLOR_WHITE, self::COLOR_BLACK);
  private $turn_count = 0;
  private $defeated_figures = array(
    self::COLOR_WHITE => array(),
    self::COLOR_BLACK => array()
  );
  private $last_pawn_double_move = array(
    self::COLOR_WHITE => -1,
    self::COLOR_BLACK => -1,
  );

  public function __construct($connection = NULL)
  {
    $this->generate_map();

    if($connection != NULL)
    {
      $this->current_connections[$connection->getPort()]['connection'] = $connection;
      $this->current_connections[$connection->getPort()]['color'] = mt_rand(0, 1) == 1 ? array_pop($this->colors) : array_shift($this->colors);
    }
  }

  /*
   * @return bool - true if this GameInstance is full false otherwise
   */
  public function is_full()
  {
    return count($this->current_connections) == 2 ? true : false;
  }

  /*
   * Adds a client to this GameInstance if the game is not full
   * @param $connection the client which should be added to the game
   * @return bool true if the client could be successfully added to this GameInstance, false otherwise
   */
  public function add_client($connection)
  {
    if(!$this->is_full())
    {
      return false;
    }

    $key = $connection->getPort();

    $this->current_connections[$key]['connection'] = $connection;
    $this->current_connections[$key]['color'] = mt_rand(0, 1) == 1 ? array_pop($this->colors) : array_shift($this->colors);

    return true;
  }

  /*
   * This method handles all incoming data for this GameInstance
   * @param $data the json encoded string with the client action
   * @param $connection the client which sent the data
   * @return void but calls a method in which an appropriated answer will be sent to the client
   */
  public function on_data($data, $connection)
  {
    if(!count($this->current_connections) == 2)
      return;

    $msg = json_decode($data, true);

    switch($msg['action'])
    {
      case 'map_request':
        $this->send_action_to_client($connection, "map_delivery");
        break;

      case 'turn':
        if($this->current_connections[$connection->getPort()]['color'] != $this->current_turn)
        {
          $this->send_action_to_client($connection, "map_delivery");
          $this->send_warning_to_client($connection, "It's not your turn, please wait ...");
          break;
        }
        $from = $msg['from'];
        $to = $msg['to'];
        $this->update_former_map();
        $movement_result = $this->process_client_movement($msg);

        if($movement_result['success'])
        {
          $this->turn_count += 1;
          $this->update_current_turn();
          foreach($this->current_connections as $client)
          {
            $this->send_movement_to_client($client, $from, $to);

            $this->send_action_to_client($client['connection'], "map_delivery");
          }

          $this->checkVitory();
        }
        else
        {
          $this->send_action_to_client($connection, "map_delivery");
          $this->send_warning_to_client($connection, $movement_result['msg']);
        }
        break;

      case 'user_msg':
        $this->echo_msg_to_users($connection, $msg['msg'], $msg['from']);
        break;
    }
  }

  /*
   * This method invokes the actual game after to clients are connected, after that is it useless and keeps calling do_step
   * which in turn does nothing
   * TODO: Replace this with something more suitable
   * @return void
   */
  public function on_update()
  {
    if(count($this->current_connections) != 2)
      return;

    $this->do_step();
  }

  /*
   * Handles the case if an client disconnected, currently it just resets the GameInstance and removes the
   * disconnected client from this GameInstance, also calls reset_all() which in turn disconnects the still
   * connected client
   * @param $connection the client who is not longer connected
   * @return void
   * TODO: Tell the still connected client that the opponent is not longer connected and that he has won
   */
  public function on_disconnect($connection)
  {
    echo "disconnect \r\n";
    foreach($this->current_connections as $key => $client)
    {
      if($client['connection']->getPort() == $connection->getPort())
      {
        unset($this->current_connections[$key]);
      }
    }

    if(count($this->current_connections) < 2)
    {
      $this->reset_all();
    }
  }

  /*
   * Echos the received message from one of clients back to him and to all other clients
   * @param $connection the client who sent the message
   * @param $msg the actual message
   * @param $from the color of the player who sent the message
   * @return void
   */
  private function echo_msg_to_users($connection, $msg, $from)
  {
    foreach($this->current_connections as $client)
    {
      $client['connection']->send(json_encode(array(
        "action" => "user_msg",
        "msg" => htmlentities($msg),
        "from" => $from
      )));
    }
  }

  private function update_former_map()
  {
    $this->former_map = $this->map;
  }

  private function send_movement_to_client($connection, $from, $to)
  {
    $differences = array();
    $differences['moved_figure'] = $this->former_map[$from]['type'];
    $differences['former_figure'] = $this->former_map[$to]['type'];
    $differences['removed_figure'] = "";
    $differences['switched'] = false;
    $differences['new_figure'] = "";


    if(
    (
      (
        $this->former_map[$from]['type'] == 'K'
          ||
        $this->former_map[$from]['type'] == 'T'
      )
        &&
      (
        (
          $this->former_map[$to]['type'] == 'K'
            ||
          $this->former_map[$to]['type'] == 'T'
        )
            &&
          $this->former_map[$to]['origin'] == $this->get_opponent()
            &&
          $this->former_map[$from]['origin'] == $this->get_opponent()
      )
    )
      )
    {
      $differences['switched'] = true;
    }
    else if
    (
      (
        $this->former_map[$from]['type'] == 'B'
          &&
          $this->map[$to]['type'] != 'B'
          &&
          $this->map[$to]['origin'] == $this->get_opponent()
      )
    )
    {
      $differences['new_figure'] = $this->map[$to]['type'];
      $differences['switched'] = true;
    }
    else
    {
      foreach($this->map as $key => $field)
      {
        if( $key != $from && $key != $to && $this->map[$key]['origin'] != $this->former_map[$key]['origin'] && $this->map[$key]['type'] == "")
        {
          $differences['former_figure'] = $this->former_map[$key]['type'];
        }
      }
    }

      $connection['connection']->send(json_encode(
        array
        (
          "action" => "movement",
          "who" => $this->get_opponent(),
          "moved_figure" =>  $differences['moved_figure'],
          "from_field" => $from,
          "to_field" => $to,
          "former_figure" => $differences['former_figure'],
          "switched" => $differences['switched'],
          "new_figure" => $differences['new_figure'],
        )
      ));


  }

  private function update_current_turn()
  {
    $this->current_turn = $this->current_turn == self::COLOR_WHITE ? self::COLOR_BLACK : self::COLOR_WHITE;
  }


  private function checkVitory() {
    $bWhiteKing = false;
    $bBlackKing = false;

    foreach( $this->map as $aMapItem ) {
      if( $aMapItem['origin'] == self::COLOR_BLACK && $aMapItem['type'] == 'K' ) {
        $bBlackKing = true;
      } else {
        if( $aMapItem['origin'] == self::COLOR_WHITE && $aMapItem['type'] == 'K' ) {
          $bWhiteKing = true;
        }
      }
    }

    $szWinner = "";
    if( $bBlackKing && !$bWhiteKing ) {
      $szWinner = self::COLOR_BLACK;
    } elseif( !$bBlackKing && $bWhiteKing ) {
      $szWinner = self::COLOR_WHITE;
    }

    if( !empty( $szWinner ) ) {
      $this->send_to_all(array(
        'action' => 'gameover',
        'winner' => $szWinner,
        'stats' => array(
          'turns' => $this->turn_count,
        )
      ));
    }
  }

  private function process_client_movement($msg)
  {
    $movement_valid = array("success" => false, "msg" => "undefined server error, please try again.");

    if($this->map[$msg['from']]['origin'] == $this->current_turn)
    {
      switch($this->map[$msg['from']]['type'])
      {
        case 'T':
          $movement_valid = $this->move_rook($msg['from'], $msg['to']);
          break;

        case 'S':
          $movement_valid = $this->move_knight($msg['from'], $msg['to']);
          break;

        case 'L':
          $movement_valid = $this->move_bishop($msg['from'], $msg['to']);
          break;

        case 'K':
          $movement_valid = $this->move_king($msg['from'], $msg['to']);
          break;

        case 'D':
          $movement_valid = $this->move_queen($msg['from'], $msg['to']);
          break;

        case 'B':
          $movement_valid = $this->move_pawn($msg['from'], $msg['to']);
          break;
        case "":
          $movement_valid['success'] = false;
          $movement_valid['msg'] = "You cannot move an empty field.";
          break;

        default:
          $movement_valid['success'] = false;
          $movement_valid['msg'] = "Map seems to be outdated..., try again.";
          break;
      }
    }

    if( $movement_valid['success'] ) {
      if( $this->last_pawn_double_move[ $this->current_turn ] != -1 && $this->last_pawn_double_move[ $this->current_turn ] != $msg['to'] ) {
        $this->last_pawn_double_move[ $this->current_turn ] = -1;
      }
    }

    return $movement_valid;
  }

  private function is_move_queen_allowed( $from, $to ) {
    return $this->is_move_bishop_allowed( $from, $to ) || $this->is_move_rook_allowed( $from, $to );
  }

  private function move_queen( $from, $to ) {
    $allowed = $this->is_move_queen_allowed( $from, $to );

    $movement_result['success'] = false;
    $movement_result['msg'] = "You're queen is not allowed to move to this position.";

    if($allowed)
    {
      $this->update_field_changes($from, $to, "D");
      $movement_result['success'] = true;
    }

    if($movement_result['success'])
      $movement_result['msg'] = "";

    return $movement_result;
  }

  private function is_move_bishop_allowed( $from, $to ) {
    $multiplier = $this->get_current_direction_multiplier();
    $opponent = $this->get_opponent();

    $allowed = false;

    $aDirections = array( true, true, true, true );
    $aReachableFields = array();
    for( $i=1; $i<9; $i++ ) {
      $row = 8 * $i;
      $col = $i;

      $index = $from + $row + $col;
      if( $aDirections[0] && $index >= 0 && $index < 64 && ( empty($this->map[$index]['origin']) || $this->map[$index]['origin'] == $opponent ) ) {
        $aReachableFields[] = $index;
        if( $this->map[$index]['origin'] == $opponent ) {
          $aDirections[0] = false;
        }
      } else {
        $aDirections[0] = false;
      }

      $index = $from + $row - $col;
      if( $aDirections[1] && $index >= 0 && $index < 64 && ( empty($this->map[$index]['origin']) || $this->map[$index]['origin'] == $opponent ) ) {
        $aReachableFields[] = $index;
        if( $this->map[$index]['origin'] == $opponent ) {
          $aDirections[1] = false;
        }
      } else {
        $aDirections[1] = false;
      }

      $index = $from - $row + $col;
      if( $aDirections[2] && $index >= 0 && $index < 64 && ( empty($this->map[$index]['origin']) || $this->map[$index]['origin'] == $opponent ) ) {
        $aReachableFields[] = $index;
        if( $this->map[$index]['origin'] == $opponent ) {
          $aDirections[2] = false;
        }
      } else {
        $aDirections[2] = false;
      }

      $index = $from - $row - $col;
      if( $aDirections[3] && $index >= 0 && $index < 64 && ( empty($this->map[$index]['origin']) || $this->map[$index]['origin'] == $opponent ) ) {
        $aReachableFields[] = $index;
        if( $this->map[$index]['origin'] == $opponent ) {
          $aDirections[3] = false;
        }
      } else {
        $aDirections[3] = false;
      }

      if( !in_array( true, $aDirections ) ) {
        break;
      }
    }

    if( in_array( $to, $aReachableFields ) ) {
      if( empty($this->map[$to]['origin']) || $this->map[$to]['origin'] == $opponent ) {
        $allowed = true;
      }
    }

    return $allowed;
  }

  private function move_bishop($from, $to)
  {
    $allowed = $this->is_move_bishop_allowed( $from, $to );

    $movement_result['success'] = false;
    $movement_result['msg'] = "You're bishop is not allowed to move to this position.";

    if($allowed)
    {
      $this->update_field_changes($from, $to, "L");
      $movement_result['success'] = true;
    }

    if($movement_result['success'])
      $movement_result['msg'] = "";

    return $movement_result;
  }

  private function is_move_knight_allowed( $from, $to ) {
    $multiplier = $this->get_current_direction_multiplier();
    $opponent = $this->get_opponent();

    $allowed = false;

    if(
      $from + 17 == $to ||
      $from - 17 == $to ||
      $from + 15 == $to ||
      $from - 15 == $to ||
      $from + 10 == $to ||
      $from - 10 == $to ||
      $from + 6 == $to ||
      $from - 6 == $to
    ) {
      if( empty($this->map[$to]['origin']) || $this->map[$to]['origin'] == $opponent ) {
        $allowed = true;
      }
    }

    return $allowed;
  }

  private function move_knight($from, $to)
  {
    $allowed = $this->is_move_knight_allowed( $from, $to );

    $movement_result['success'] = false;
    $movement_result['msg'] = "You're knight is not allowed to move to this position.";

    if($allowed)
    {
      $this->update_field_changes($from, $to, "S");
      $movement_result['success'] = true;
    }

    if($movement_result['success'])
      $movement_result['msg'] = "";

    return $movement_result;
  }

  private function is_move_rook_allowed($from, $to)
  {
    $opponent = $this->get_opponent();

    $allowed = true;

    $modifier = $to < $from ? -1 : 1;
    $move_min = floor($from / 8) * 8;
    $move_max = $move_min + 7;
    if($to >= $move_min && $to <= $move_max)
    {
      for($new = $from; $new != $to; $new += $modifier)
      {
        $next = $new + $modifier;

        if(!isset($this->map[$next]))
        {
          $allowed = false;
          break;
        }

        if( !( ( ($next - $new) == $modifier) &&
          ($this->map[$next]['type'] == "" || ($this->map[$next]['origin'] == $opponent && $next == $to ) ) ) )
        {
          $allowed = false;
          break;
        }
      }
    }
    else
    {
      for($new = $from; $new != $to; $new += (8 * $modifier))
      {
        $next = $new + (8 * $modifier);

        if(!isset($this->map[$next]))
        {
          $allowed = false;
          break;
        }

        if( !( ($next - $new) == ($modifier * 8) &&
          ($this->map[$next]['type'] == "" || ($this->map[$next]['origin'] == $opponent && $next == $to ) ) ) )
        {
          $allowed = false;
          break;
        }
      }
    }

    return $allowed;
  }

  private function move_rook($from, $to)
  {
    $allowed = $this->is_move_rook_allowed($from, $to);

    $movement_result['success'] = false;
    $movement_result['msg'] = "You're rook is not allowed to move to this position.";

    if($allowed)
    {
      $this->update_field_changes($from, $to, "T");
      $movement_result['success'] = true;
    }

    if(!$movement_result['success'])
    {
      $movement_result['success'] = $this->check_castling($from, $to);
    }

    if($movement_result['success'])
      $movement_result['msg'] = "";

    return $movement_result;
  }


  private function check_castling($from, $to)
  {
    $multiplier = $this->get_current_direction_multiplier();

    $allowed = true;

    if( ( ($this->map[$from]['moved'] == false && ($this->map[$from]['type'] == 'K' || $this->map[$from]['type'] == 'T') ) &&
      ($this->map[$to]['moved'] == false && ($this->map[$to]['type'] == 'K' || $this->map[$to]['type'] == 'T')) ) &&
      ( ($to - $from) == ($multiplier * 3) || ($to - $from) == ($multiplier * -3) ||
        ($to - $from) == ($multiplier * 4) || ($to - $from) == ($multiplier * -4) ) )
    {
      $modifier = $to < $from ? -1 : 1;
      for($new = $from; $new != $to; $new += $modifier)
      {
        $next = $new + $modifier;

        if(!isset($this->map[$next]))
        {
          $allowed = false;
          break;
        }

        if( $this->map[$next]['type'] != "" && $next != $to  )
        {
          $allowed = false;
          break;
        }
      }
    }
    else
    {
      $allowed = false;
    }

    if($allowed)
    {
      $to_type = $this->map[$to]['type'];
      $to_origin = $this->map[$to]['origin'];

      $this->map[$to] = array("type" => $this->map[$from]['type'], "origin" => $this->map[$from]['origin'], "moved" => true);
      $this->map[$from] = array("type" => $to_type, "origin" => $to_origin, "moved" => true);
    }

    return $allowed;
  }

  private function move_king($from, $to)
  {

    $allowed = $this->is_move_king_allowed($from, $to);

    $movement_result['success'] = false;
    $movement_result['msg'] = "You're king is not permitted to go there.";

    // TODO: Find a better way to check for castling for the king
    if(!$allowed)
    {
      $allowed = $this->check_castling($from, $to);
    }

    if($allowed)
    {
      $movement_result['msg'] = "";
      $movement_result['success'] = $allowed;
    }

    return $movement_result;
  }

  private function is_move_king_allowed($from, $to)
  {
    $multiplier = $this->get_current_direction_multiplier();
    $opponent = $this->get_opponent();
    $movement_result = false;

    if( ( ($to - $from) == ($multiplier * 8) || ($to - $from) == ($multiplier * -8) ||
        ($to - $from) == ($multiplier * 7) || ($to - $from) == ($multiplier * 9) ||
        ($to - $from) == ($multiplier * -7) || ($to - $from) == ($multiplier * -9) ||
        ($to - $from) == ($multiplier * -1) || ($to - $from) == ($multiplier * 1)) &&
      isset($this->map[$to]) && ( $this->map[$to]['origin'] == $opponent || $this->map[$to]['type'] == "") )
    {
      $this->update_field_changes($from, $to, "K");
      $movement_result = true;
    }

    return $movement_result;
  }

  private function move_pawn($from, $to)
  {
    $multiplier = $this->get_current_direction_multiplier();
    $opponent = $this->get_opponent();
    $movement_result['success'] = false;
    $movement_result['msg'] = "You're not permitted to move your pawn to this location.";

    if( ( $to - $from ) == ($multiplier * 8) && $this->map[$to]['type'] == "")
    {
      $this->update_field_changes($from, $to, "B");

      $movement_result['success'] = true;
    }
    else if( $this->map[$from]['moved'] === false && ( $to - $from ) == ($multiplier * 16)
      && $this->map[$to]['type'] == "" && $this->map[$from + ($multiplier * 8)]['type'] == "" )
    {
      $this->update_field_changes($from, $to, "B");

      $movement_result['success'] = true;
    }
    else if( ($to - $from) == ($multiplier * 7) && $this->map[$from + ($multiplier * 7)]['origin'] == $opponent
      || ($to - $from) == ($multiplier * 9) && $this->map[$from + ($multiplier * 9)]['origin'] == $opponent)
    {
      $this->update_field_changes($from, $to, "B");
      $movement_result['success'] = true;
    } elseif( // en passant
      $this->last_pawn_double_move[ $opponent ] != -1
      &&
      (
        (
          ( $this->last_pawn_double_move[ $opponent ] > $from && $this->last_pawn_double_move[ $opponent ] == $from+1 )
          &&
          (
            $multiplier < 0 && $to - $from == 7 * $multiplier
            ||
            $multiplier > 0 && $to - $from == 9 * $multiplier
          )
        )
        ||
        (
          ( $this->last_pawn_double_move[ $opponent ] < $from && $this->last_pawn_double_move[ $opponent ] == $from-1 )
          &&
          (
            $multiplier < 0 && $to - $from == 9 * $multiplier
            ||
            $multiplier > 0 && $to - $from == 7 * $multiplier
          )
        )
      )
    ) {
      $this->update_field_changes($from, $to, "B");
      $this->update_field_changes($this->last_pawn_double_move[ $opponent ], $this->last_pawn_double_move[ $opponent ], "");
      $movement_result['success'] = true;
    }


    if($movement_result['success']) {
      $movement_result['msg'] = "";

      if( abs($from - $to) == 16 ) {
        $this->last_pawn_double_move[ $this->current_turn ] = $to;
      }

      if(
        ( $multiplier > 0 && $to / 8 >= 7 )
        ||
        ( $multiplier < 0 && $to < 8 )
      ) {
        $szFigure = $this->getBestLostFigure( $this->current_turn );
        if( isset($this->defeated_figures[$this->current_turn][$szFigure]) ) {
          $this->defeated_figures[$this->current_turn][$szFigure]--;
        }
        $this->update_field_changes($to, $to, $szFigure, false);
      }
    }

    return $movement_result;
  }

  private function getBestLostFigure( $szPlayer ) {
    $szBest = 1;
    foreach( $this->defeated_figures[$szPlayer] as $szFigure => $iCount ) {
      if( $szFigure == 'D' ) {
        $szBest = 5;
        break;
      } elseif( $szBest < 4 && $szFigure == 'T' ) {
        $szBest = 4;
      } elseif( $szBest < 3 && $szFigure == 'L' ) {
        $szBest = 3;
      } elseif( $szBest < 3 && $szFigure == 'S' ) {
        $szBest = 2;
      } elseif( $szBest < 2 && $szFigure == 'B' ) {
        $szBest = 1;
      }


    }

    $aFigures = array(
      5 => 'D',
      4 => 'T',
      3 => 'L',
      2 => 'S',
      1 => 'B'
    );

    return $aFigures[ $szBest ];
  }


  /*
   */
  private function update_field_changes($from, $to, $new_type, $bClear = true)
  {
    if($this->map[$to]['type'] != "" && $this->map[$to]['origin'] != "")
    {
      $this->defeated_figures[$this->map[$to]['origin']][$this->map[$to]['type']] =
        isset($this->defeated_figures[$this->map[$to]['origin']][$this->map[$to]['type']]) ?
          $this->defeated_figures[$this->map[$to]['origin']][$this->map[$to]['type']]++ : 1;
    }

    $this->map[$to] = array("type" => $new_type, "origin" => $this->map[$from]['origin'], "moved" => true);
    if( $bClear ) {
      $this->map[$from] = array("type" => "", "origin" => "", "moved" => false);
    }
  }

  private function get_opponent()
  {
    return $this->current_turn == self::COLOR_WHITE ? self::COLOR_BLACK : self::COLOR_WHITE;
  }

  private function get_current_direction_multiplier()
  {
    return $this->current_turn == self::COLOR_WHITE ? 1 : -1;
  }

  private function get_start_field()
  {
    return $this->current_turn == self::COLOR_WHITE ? 0 : 63;
  }

  private function send_warning_to_client($client, $warningmsg)
  {
    $client->send(json_encode(
      array(
        "action" => "warning",
        "msg" => $warningmsg
      )));
  }

  private function send_error_to_client($client, $errormsg)
  {
    $client->send(json_encode(
      array(
        "action" => "error",
        "msg" => $errormsg
      )));
  }

  private function reset_all()
  {
    $this->generate_map();

    foreach($this->current_connections as $client)
    {
      $client['connection']->close();
    }

    unset($this->current_connections);
    $this->current_connections = array();
    $this->colors = array(self::COLOR_WHITE, self::COLOR_BLACK);
    $this->step = 0;
    $this->turn_count = 0;
  }

  private function send_action_to_client($client, $action)
  {
    $client->send(json_encode(
      array(
        "action" => $action,
        "active_user" => $this->current_turn,
        "color" => $this->current_connections[$client->getPort()]['color'],
        "map" => $this->map,
        "defeated" => $this->get_defeated_figures()
      )));
  }

  private function send_to_all( $aMessage ) {
    foreach($this->current_connections as $client)
    {
      $client['connection']->send(json_encode( $aMessage ) );
    }
  }

  private function get_defeated_figures()
  {
    return json_encode($this->defeated_figures);
  }

  private function do_step()
  {
    switch($this->step)
    {
      case 0:
        foreach($this->current_connections as $client)
        {
          $client['connection']->send(json_encode(
            array(
              "action" => "ready",
              "color" => $client['color']
            )));
        }
        $this->step++;
        break;

      case 1:
        break;



    }
  }



  private function generate_map()
  {
    $origin = self::COLOR_WHITE;

    for($i = 0; $i < 64; $i++)
    {
      if($origin != self::COLOR_BLACK && $i > 31)
      {
        $origin = self::COLOR_BLACK;
      }

      switch($i)
      {
        case 0:
        case 7:
        case 56:
        case 63:
          $this->map[$i] = array("type" => "T", "origin" => $origin, "moved" => false);
          break;

        case 1:
        case 6:
        case 57:
        case 62:
          $this->map[$i] = array("type" => "S", "origin" => $origin, "moved" => false);
          break;

        case 2:
        case 5:
        case 58:
        case 61:
          $this->map[$i] = array("type" => "L", "origin" => $origin, "moved" => false);
          break;

        case 3:
        case 59:
          $this->map[$i] = array("type" => "K", "origin" => $origin, "moved" => false);
          break;

        case 4:
        case 60:
          $this->map[$i] = array("type" => "D", "origin" => $origin, "moved" => false);
          break;

        default:
          if( ($i > 7 && $i < 16) || ($i > 47 && $i < 56) )
          {
            $this->map[$i] = array("type" => "B", "origin" => $origin, "moved" => false);
            break;
          }
          else
          {
            $this->map[$i] = array("type" => "", "origin" => "", "moved" => false);
            break;
          }
      }
    }
  }

}
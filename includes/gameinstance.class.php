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
  private $game_over = false;
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

  /**
   * @return bool - true if this GameInstance is full false otherwise
   */
  public function is_full()
  {
    return count($this->current_connections) == 2 ? true : false;
  }

  /**
   * Adds a client to this GameInstance if the game is not full
   * @param object $connection the client which should be added to the game
   * @return bool true if the client could be successfully added to this GameInstance, false otherwise
   */
  public function add_client($connection)
  {
    if($this->is_full())
    {
      return false;
    }

    $key = $connection->getPort();

    $this->current_connections[$key]['connection'] = $connection;
    $this->current_connections[$key]['color'] = mt_rand(0, 1) == 1 ? array_pop($this->colors) : array_shift($this->colors);

    return true;
  }

  /**
   * This method handles all incoming data for this GameInstance.
   * Return void but calls a method in which an appropriated answer will be sent to the client.
   * @param string $data the json encoded string with the client action
   * @param object $connection the client which sent the data
   */
  public function on_data($data, $connection)
  {
    if(!count($this->current_connections) == 2)
      return;

    $msg = json_decode($data, true);

    switch($msg['action'])
    {
      case 'map_request':
        $this->send_map_delivery($connection);
        break;

      case 'turn':
        if($this->current_connections[$connection->getPort()]['color'] != $this->current_turn)
        {
          $this->send_map_delivery($connection);
          $this->send_warning_to_client($connection, "It's not your turn, please wait ...");
          break;
        }

        if($this->game_over === true)
        {
          $this->send_warning_to_client($connection, "The game is over if you want to play again reconnect to the server.");
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

            $this->send_map_delivery($client['connection']);
          }

          $this->checkVitory();

          $this->sendCheckIfNecessary();
        }
        else
        {
          $this->send_map_delivery($connection);
          $this->send_warning_to_client($connection, $movement_result['msg']);
        }
        break;

      case 'user_msg':
        if( !empty($msg['msg']) ) {
          $this->echo_msg_to_users($msg['msg'], $msg['from']);
        }
        break;
    }
  }

  /**
   * Send check messages if a king is in danger
   */
  private function sendCheckIfNecessary() {
    foreach( $this->map as $iFieldPosition => $aMapItem ) {
      if( !empty($aMapItem['type']) && $aMapItem['type'] == 'K' ) {

        if( $this->checkFieldInDanger( self::COLOR_BLACK, $iFieldPosition, $iFieldPosition ) ) {
          $bCheckmate = $this->is_checkmate( self::COLOR_BLACK, $iFieldPosition );
          if( $bCheckmate ) {
            $this->echo_msg_to_users("CHECKMATE!", self::COLOR_WHITE);
          } else {
            $this->echo_msg_to_users("CHECK!", self::COLOR_WHITE);
          }
          break;
        } elseif( $this->checkFieldInDanger( self::COLOR_WHITE, $iFieldPosition, $iFieldPosition ) ) {
          $bCheckmate = $this->is_checkmate( self::COLOR_WHITE, $iFieldPosition );
          if( $bCheckmate ) {
            $this->echo_msg_to_users("CHECKMATE!", self::COLOR_BLACK);
          } else {
            $this->echo_msg_to_users("CHECK!", self::COLOR_BLACK);
          }
          break;
        }

      }
    }
  }

  /**
   * This method invokes the actual game after to clients are connected, after that is it useless and keeps calling do_step
   * which in turn does nothing
   * TODO: Replace this with something more suitable
   */
  public function on_update()
  {
    if(count($this->current_connections) != 2)
      return;

    $this->do_step();
  }

  /**
   * Handles the case if an client disconnected, currently it just resets the GameInstance and removes the
   * disconnected client from this GameInstance, also calls reset_all() which in turn disconnects the still
   * connected client
   * TODO: Tell the still connected client that the opponent is not longer connected and that he has won
   * @param object $connection the client who is not longer connected
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

  /**
   * Echos the received message from one of clients back to him and to all other clients
   * @param string $msg the actual message
   * @param string $from the color of the player who sent the message
   */
  private function echo_msg_to_users($msg, $from)
  {
    foreach($this->current_connections as $client)
    {
      if( mb_detect_encoding( $msg ) != 'UTF-8' ) {
        $msg = utf8_encode( $msg );
      }

      $client['connection']->send(json_encode(array(
        "action" => "user_msg",
        "msg" => htmlentities($msg),
        "from" => $from
      )));
    }
  }

  /**
   * updates former map with current map for difference check
   */
  private function update_former_map()
  {
    $this->former_map = $this->map;
  }

  /**
   * Calculates the differences between former and current map
   * @param object $connection
   * @param int $from
   * @param int $to
   */
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

  /**
   * Change current player to opponent
   */
  private function update_current_turn()
  {
    $this->current_turn = $this->current_turn == self::COLOR_WHITE ? self::COLOR_BLACK : self::COLOR_WHITE;
  }

  /**
   * Executes the movement of the figures if possible
   * @param string $szType
   * @param int $iFrom
   * @param int $iTo
   * @return array
   */
  private function move( $szType, $iFrom, $iTo ) {
    $movement_valid = array();

    switch( $szType )
    {
      case 'T':
        $movement_valid = $this->move_rook($iFrom, $iTo);
        break;

      case 'S':
        $movement_valid = $this->move_knight($iFrom, $iTo);
        break;

      case 'L':
        $movement_valid = $this->move_bishop($iFrom, $iTo);
        break;

      case 'K':
        $movement_valid = $this->move_king($iFrom, $iTo);
        break;

      case 'D':
        $movement_valid = $this->move_queen($iFrom, $iTo);
        break;

      case 'B':
        $movement_valid = $this->move_pawn($iFrom, $iTo);
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

    return $movement_valid;
  }

  /**
   * Returns an array with movement success information
   * @param string $szType
   * @param int $iFrom
   * @param int $iTo
   * @return array
   */
  private function is_move_allowed( $szType, $iFrom, $iTo ) {
    $movement_valid = array(
      'success' => false,
      'msg' => ''
    );

    switch( $szType )
    {
      case 'T':
        $movement_valid['success'] = $this->is_move_rook_allowed($iFrom, $iTo);
        break;

      case 'S':
        $movement_valid['success'] = $this->is_move_knight_allowed($iFrom, $iTo);
        break;

      case 'L':
        $movement_valid['success'] = $this->is_move_bishop_allowed($iFrom, $iTo);
        break;

      case 'K':
        $movement_valid['success'] = $this->is_move_king_allowed($iFrom, $iTo);
        break;

      case 'D':
        $movement_valid['success'] = $this->is_move_queen_allowed($iFrom, $iTo);
        break;

      case 'B':
        $movement_valid['success'] = $this->is_move_pawn_allowed($iFrom, $iTo);
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

   return $movement_valid['success'];
  }

  /**
   * Returns ture if the filed can be conquered by opponent
   * @param string $origin color
   * @param int $iFrom map index
   * @param int $iPositionDefender map index
   * @return bool
   */
  private function checkFieldInDanger( $origin, $iFrom, $iPositionDefender ) {
    $aTempMap = $this->map;

    if( $iFrom != $iPositionDefender ) {
      $this->update_field_changes( $iFrom, $iPositionDefender, $this->map[$iFrom]['type'] );
    }

    $oldOrigin = $this->current_turn;
    $this->current_turn = $this->get_opponent( $origin );

    foreach( $this->map as $iPositionAttacker => $aMapItem ) {
      if( !empty($aMapItem['origin']) && $aMapItem['origin'] != $origin && $iPositionDefender != $iPositionAttacker ) {
        $bAllowed = $this->is_move_allowed( $aMapItem['type'], $iPositionAttacker, $iPositionDefender );

        if( $bAllowed ) {
          $this->current_turn = $oldOrigin;
          $this->map = $aTempMap;
          return true;
        }
      }
    }
    $this->current_turn = $oldOrigin;
    $this->map = $aTempMap;
    return false;
  }

  /**
   * Checks for game over and send game over message
   */
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

    if( !empty( $szWinner ) )
    {
      $this->send_to_all(array(
        'action' => 'gameover',
        'winner' => $szWinner,
        'stats' => array(
          'turns' => $this->turn_count,
        )
      ));
      $this->game_over = true;
    }
  }

  /**
   * Proccess the client moment actions
   * @param string $msg
   * @return array
   */
  private function process_client_movement($msg)
  {
    $movement_valid = array("success" => false, "msg" => "undefined server error, please try again.");

    if($this->map[$msg['from']]['origin'] == $this->current_turn)
    {
      $movement_valid = $this->move( $this->map[$msg['from']]['type'], $msg['from'], $msg['to'] );
    }

    if( $movement_valid['success'] ) {
      if( $this->last_pawn_double_move[ $this->current_turn ] != -1 && $this->last_pawn_double_move[ $this->current_turn ] != $msg['to'] ) {
        $this->last_pawn_double_move[ $this->current_turn ] = -1;
      }
    }

    return $movement_valid;
  }

  /**
   * Returns true if the queen can move to field
   * @param int $from
   * @param int $to
   * @return bool
   */
  private function is_move_queen_allowed( $from, $to ) {
    return $this->is_move_bishop_allowed( $from, $to ) || $this->is_move_rook_allowed( $from, $to );
  }


  /**
   * Moves the queen
   * @param int $from
   * @param int $to
   * @return array
   */
  private function move_queen( $from, $to ) {
    $allowed = $this->is_move_queen_allowed( $from, $to );

    $movement_result['success'] = false;
    $movement_result['msg'] = "Your queen is not allowed to move to this position.";

    if($allowed)
    {
      $this->update_field_changes($from, $to, "D");
      $movement_result['success'] = true;
    }

    if($movement_result['success'])
      $movement_result['msg'] = "";

    return $movement_result;
  }

  /**
   * Return movement information of the bishop
   * @param int $from
   * @param int $to
   * @return bool
   */
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

  /**
   * Moves the Bishop
   * @param int $from
   * @param int $to
   * @return array
   */
  private function move_bishop($from, $to)
  {
    $allowed = $this->is_move_bishop_allowed( $from, $to );

    $movement_result['success'] = false;
    $movement_result['msg'] = "Your bishop is not allowed to move to this position.";

    if($allowed)
    {
      $this->update_field_changes($from, $to, "L");
      $movement_result['success'] = true;
    }

    if($movement_result['success'])
      $movement_result['msg'] = "";

    return $movement_result;
  }

  /**
   * Returns true if the knight can move to field
   * @param int $from
   * @param int $to
   * @return bool
   */
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

  /**
   * Moves the Knight
   * @param int $from
   * @param int $to
   * @return array
   */
  private function move_knight($from, $to)
  {
    $allowed = $this->is_move_knight_allowed( $from, $to );

    $movement_result['success'] = false;
    $movement_result['msg'] = "Your knight is not allowed to move to this position.";

    if($allowed)
    {
      $this->update_field_changes($from, $to, "S");
      $movement_result['success'] = true;
    }

    if($movement_result['success'])
      $movement_result['msg'] = "";

    return $movement_result;
  }

  /**
   * Returns true if the rook can move to field
   * @param int $from
   * @param int $to
   * @return bool
   */
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

  /**
   * Moves the Rook
   * @param int $from
   * @param int $to
   * @return array
   */
  private function move_rook($from, $to)
  {
    $allowed = $this->is_move_rook_allowed($from, $to);

    $movement_result['success'] = false;
    $movement_result['msg'] = "Your rook is not allowed to move to this position.";

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

  /**
   * Returns true if rook and king can be switched
   * @param int $from
   * @param int $to
   * @return bool
   */
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

  /**
   * Returns true if from is checkmate
   * @param string $origin color
   * @param int $from map index
   */
  private function is_checkmate( $origin, $from ) {
    $bCheckmate = true;

    $aTargets = array(
      $from - 9,
      $from - 8,
      $from - 7,
      $from - 1,
      $from + 1,
      $from + 7,
      $from + 8,
      $from + 9,
    );

    foreach( $aTargets as $iTarget ) {
      if( $iTarget >= 0 && $iTarget <= 63 ) {
        if( $this->is_move_king_allowed($from, $iTarget) ) {
          if( !$this->checkFieldInDanger( $origin, $from, $iTarget ) ) {
            $bCheckmate = false;
          }
        }
      }
    }

    return $bCheckmate;
  }

  /**
   * Moves the king
   * @param int $from
   * @param int $to
   * @return array
   */
  private function move_king($from, $to)
  {
    $allowed = $this->is_move_king_allowed($from, $to);

    $movement_result = array();

    if( $allowed ) {
      if( $this->checkFieldInDanger( $this->current_turn, $from, $to ) ) {
        // ---------------------------------------------------------------------------------------------

        $bCheckmate = $this->is_checkmate( $this->current_turn, $from );

        if( !$bCheckmate ) {
          $movement_result['success'] = false;
          $movement_result['msg'] = 'Your King can not be moved to a field where he can be conquered';

          return $movement_result;
        }

        // ---------------------------------------------------------------------------------------------
      }
    }

    $movement_result['success'] = false;
    $movement_result['msg'] = "Your king is not permitted to go there.";

    // TODO: Find a better way to check for castling for the king
    if(!$allowed)
    {
      $allowed = $this->check_castling($from, $to);
    }

    if($allowed)
    {
      $this->update_field_changes($from, $to, "K");
      $movement_result['msg'] = "";
      $movement_result['success'] = $allowed;
    }

    return $movement_result;
  }

  /**
   * Returns true if the king can move to field
   * @param int $from
   * @param int $to
   * @return bool
   */
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
      $movement_result = true;
    }

    return $movement_result;
  }

  /**
   * Returns true if the pawn can move to field
   * @param int $from
   * @param int $to
   * @return bool
   */
  private function is_move_pawn_allowed( $from, $to ) {
    $multiplier = $this->get_current_direction_multiplier();
    $opponent = $this->get_opponent();
    $allowed = false;

    if( ( $to - $from ) == ($multiplier * 8) && $this->map[$to]['type'] == "")
    {
      $allowed = true;
    }
    else if( $this->map[$from]['moved'] === false && ( $to - $from ) == ($multiplier * 16)
      && $this->map[$to]['type'] == "" && $this->map[$from + ($multiplier * 8)]['type'] == "" )
    {
      $allowed = true;
    }
    else if( ($to - $from) == ($multiplier * 7) && $this->map[$from + ($multiplier * 7)]['origin'] == $opponent
      || ($to - $from) == ($multiplier * 9) && $this->map[$from + ($multiplier * 9)]['origin'] == $opponent)
    {
      $allowed = true;
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
      $allowed = true;
    }

    return $allowed;
  }

  /**
   * Moves the pawn
   * @param int $from
   * @param int $to
   * @return array
   */
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

  /**
   * Return the best defeated figure of the player
   * @param string $szPlayer
   * @return string
   */
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


  /**
   * Executes a figure move operation
   * @param int $from position
   * @param int $to position
   * @param string $new_type figure
   * @param bool $bClear if true the field is set to empty after move
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

  /**
   * Return the current opponent color
   * @param string|null $current
   * @return string
   */
  private function get_opponent( $current = NULL )
  {
    if( is_null( $current ) ) {
      $current = $this->current_turn;
    }
    return $current == self::COLOR_WHITE ? self::COLOR_BLACK : self::COLOR_WHITE;
  }

  /**
   * Returns the current pawn move direction
   * @return int
   */
  private function get_current_direction_multiplier()
  {
    return $this->current_turn == self::COLOR_WHITE ? 1 : -1;
  }

  /**
   * Send a warn message to a specific client
   * @param object $client
   * @param string $warningmsg
   */
  private function send_warning_to_client($client, $warningmsg)
  {
    $client->send(json_encode(
      array(
        "action" => "warning",
        "msg" => $warningmsg
      )));
  }

  /**
   * Send a error message to a specific client
   * @param object $client
   * @param string $errormsg
   */
  private function send_error_to_client($client, $errormsg)
  {
    $client->send(json_encode(
      array(
        "action" => "error",
        "msg" => $errormsg
      )));
  }

  /**
   * Reset game to default
   */
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
    $this->current_turn = self::COLOR_WHITE;
    $this->game_over = false;
  }

  /**
   * Answer on an map_request message
   * @param object $client
   * @param string $action
   */
  private function send_map_delivery($client)
  {
    $aMap = array();

    foreach( $this->map as $aMapItem ) {
      $aMap[] = array(
        'type'    => $aMapItem['type'],
        'origin'  => $aMapItem['origin']
      );
    }

    $client->send(json_encode(
      array(
        "action" => "map_delivery",
        "active_user" => $this->current_turn,
        "color" => $this->current_connections[$client->getPort()]['color'],
        "map" => $aMap,
        "defeated" => $this->get_defeated_figures()
      )));
  }

  /**
   * Send data to all clients
   * @param array $aMessage
   */
  private function send_to_all( $aMessage ) {
    foreach($this->current_connections as $client)
    {
      $client['connection']->send(json_encode( $aMessage ) );
    }
  }

  /**
   * Returns the defeated figures as json string
   * @return string json
   */
  private function get_defeated_figures()
  {
    return json_encode($this->defeated_figures);
  }

  /**
   * Interval function will be called repeatedly several times per second
   */
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

  /**
   * Generates the default map
   */
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
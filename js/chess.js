var map = new Map();
var game = new Game();

window.onbeforeunload = function() {
  if( game.isConnected ) {
    return "You are currently connected to a game.\r\nIf you proceed you can not resume this game.";
  }
};

$(document).ready(function(){
  $('#connect').on('click',function(){
    game.connect( $('#host').val() );
  });

  $('#disconnect').on('click',function(){
    game.disconnect();
  });

  $('#showLog').on('click',function(){
    $('#log').toggle();
  });

  $('#MsgSendButton').on('click', function(e)
  {
    if($('#MsgSendField').length == 1)
    {
      if( $('#MsgSendField').val() != '' ) {
        game.send_user_msg($('#MsgSendField').val());
        $('#MsgSendField').val("");
      }
    }

    e.preventDefault();
  });

  map.render();
});

// ---------------------------------------------------------------------------

function log( msg ) {
  $('#log').prepend( msg + "\n------------------------------\n" );
}

function Game() {}
Game.prototype.ws = null;
Game.prototype.isConnected = false;
Game.prototype.user = null;
Game.prototype.activeUser = null;
Game.prototype.started = false;
Game.prototype.currentSelection = null;

Game.prototype.turn = function( from, to ) {
  this.ws.send( JSON.stringify({
    action: 'turn',
    from: from,
    to: to
  }) );
}

Game.prototype.send_user_msg = function( msg )
{
  if(this.ws)
  {
    this.ws.send( JSON.stringify({
      action: 'user_msg',
      msg: msg,
      from: game.user
    }));
  }
}

Game.prototype.disconnect = function() {
  if( this.ws ) {
    log("disconnect");
    this.log("disconnect");
    this.ws.close();
    this.isConnected = false;
  }
}

Game.prototype.connect = function( target ) {
  var websocket = new WebSocket( target );
  var self = this;

  websocket.onopen = function() {
    log("connected ...");

    websocket.send("Lorem ipsum dolor sit amet, consetetur sadipscing elitr, sed diam nonumy eirmod tempor invidunt ut labore et dolore magna aliquy");
    log("welcome msg send ...");

    self.log("connected ... welcome");

    self.isConnected = true;
  };

  websocket.onmessage = function( event ) {
    var msg = event.data;
    log("Message is received... " + msg);

    var json;
    try {
      json = $.parseJSON( msg );
    } catch( e ) {
      log("Message Error: Json not well formed");
    }

    switch( json.action ) {
      case 'ready':
        self.user = json.color;
        $('#ownUser').html( "You're the <b>" + self.user + "</b> Player").fadeIn();
        websocket.send( JSON.stringify({ action: 'map_request' }) );
        self.log('opponent is ready');
        self.log('game started');
        break;

      case 'gameover':
        self.log( "GAME OVER - " + json.winner + " wins after " + json.stats.turns + " turns" );
        break;

      case 'map_delivery':
        if( self.activeUser != json.active_user ) {
          $('#turnAudio')[0].play();
        }
      
        self.setActiveUser( json.active_user );
        self.setStarted( true );

        map.update( json.map );
        break;

      case 'movement':
        var msg = json.who + " moved " + map.getFigureSymbol( json.moved_figure, json.who )
                + " from " + map.getFieldName( json.from_field )
                + " to " + map.getFieldName( json.to_field );

        if( json.former_figure != '' ) {
          if( !json.switched ) {
            msg += " and conquered " + map.getFigureSymbol( json.former_figure, json.who == 'white' ? 'black' : 'white' );
          } else if( json.switched && json.new_figure != '' ) {
            msg += " and got replaced by " + map.getFigureSymbol( json.new_figure, json.who );
          } else {
            msg += " and switched positions with " + map.getFigureSymbol( json.former_figure, json.who );
          }
        }

        self.log( msg );
        break;

      case 'info':
        self.log( "Info: " + json.msg );
      break;

      case 'warning':
        self.log( "Warning: " + json.msg );
        break;

      case 'error':
        log("ServerError: " + json.msg );
        self.log( "ServerError: " + json.msg );
        break;

      case 'user_msg':
        var opponent = game.user == 'white' ? 'black' : 'white';
        var from = json.from == game.user ? game.user : opponent;

        if(json.from != game.user)
        {
          $('#chatAudio')[0].play();
          game.log("<i>" + from + ": "+ json.msg + "</i>");
        }
        else
        {
          game.log("<i>" + "You: "+ json.msg + "</i>");
        }

        break;

      default:
        log("Unknown command receipt: " + json.action);
    }
  };

  websocket.onclose = function() {
    log("Connection is closed...");
    self.log("Connection is closed ... disconnected");
    self.isConnected = false;
  };

  websocket.onerror = function( event ) {
    log("Error: " + event.data);
    self.log("Connection error occurred (see log for details)");
  }

  this.ws = websocket;
}

Game.prototype.setActiveUser = function( activeUser ) {
  this.activeUser = activeUser;
}

Game.prototype.setStarted = function( started ) {
  this.started = started;
}

Game.prototype.isGameStarted = function() {
  return this.started == true;
}

Game.prototype.log = function( msg ) {
  var currentdate = new Date();
  $('#gameLog').prepend( '<span class="chatTime">['+ ('0'+currentdate.getHours()).slice(-2) +":" + ('0'+currentdate.getMinutes()).slice(-2) + ":" + ('0'+currentdate.getSeconds()).slice(-2) +"] </span>" + msg + "\n" );
}

// ---------------------------------------------------------------------------

function Map() {}
Map.prototype.mapData = null;
Map.prototype.mapId = '#map';

Map.prototype.update = function( map ) {
  this.mapData = map;
  this.render();
}

Map.prototype.NamesAZ = new Array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H' );
Map.prototype.Names13 = new Array( '1', '2', '3', '4', '5', '6', '7', '8' );

Map.prototype.getFieldName = function( index ) {
  return this.NamesAZ[ index % 8 ] + this.Names13[ Math.floor( index / 8 ) ];
}

Map.prototype.getFigureSymbol = function( key, owner ) {
  var origin = owner;
  var type = "";

  switch( key ) {
    case 'K':
      if( origin == 'white' )
        type = "♔";
      else
        type = "♚";
      break;

    case 'D':
      if( origin == 'white' )
        type = "♕";
      else
        type = "♛";
      break;

    case 'T':
      if( origin == 'white' )
        type = "♖";
      else
        type = "♜";
      break;

    case 'L':
      if( origin == 'white' )
        type = "♗";
      else
        type = "♝";
      break;

    case 'S':
      if( origin == 'white' )
        type = "♘";
      else
        type = "♞";
      break;

    case 'B':
      if( origin == 'white' )
        type = "♙";
      else
        type = "♟";
      break;
  }

  return type;
}

Map.prototype.render = function() {
  $(this.mapId).html('');

  if( game.user && game.user == 'black' ) {
    $(this.mapId).append('<div class="mapField mapLabel"> </div>');
    for( var i=0;i<8;i++ ) {
      $(this.mapId).append('<div class="mapField mapLabel">' + this.NamesAZ[i] + '</div>');
    }
    $(this.mapId).append('<div class="mapField mapLabel"> </div>');
  } else {
    $(this.mapId).append('<div class="mapField mapLabel"> </div>');
    for( var i=7;i>=0;i-- ) {
      $(this.mapId).append('<div class="mapField mapLabel">' + this.NamesAZ[i] + '</div>');
    }
    $(this.mapId).append('<div class="mapField mapLabel"> </div>');
  }

  var bar;
  var check;
  var i;
  if( game.user && game.user == 'black' ) {
    i=0;
    bar = 0;
    check = 7;
  } else {
    i=63;
    bar = 1;
    check = 1;
  }

  for( ;; ) {
    if( (i+bar) % 8 == 0 ) {
      $(this.mapId).append('<div class="mapField mapLabel">' + this.Names13[Math.floor(i / 8)] + '</div>');
    }

    var origin = this.mapData != null ? this.mapData[i].origin : '';
    var type = "";

    if( this.mapData != null ) {
      type = this.getFigureSymbol( this.mapData[i].type, origin );
    }

    $(this.mapId).append('<div id="field_'+i+'" class="mapField mapField_' + ( ( i + Math.floor( i / 8) % 2 ) % 2 ? 'black' : 'white' ) + ' fieldType_' + origin + '">' + type + '</div>');

    if( (i+bar) % 8 == check ) {
      $(this.mapId).append('<div class="mapField mapLabel">' + this.Names13[Math.floor(i / 8)] + '</div>');
    }

    if( game.user && game.user == 'black' ) {
      i++;
      if( i >= 64 ) break;
    } else {
      i--;
      if( i < 0 ) break;
    }
  }

  if( game.user && game.user == 'black' ) {
    $(this.mapId).append('<div class="mapField mapLabel"> </div>');
    for( var i=0;i<8;i++ ) {
      $(this.mapId).append('<div class="mapField mapLabel">' + this.NamesAZ[i] + '</div>');
    }
    $(this.mapId).append('<div class="mapField mapLabel"> </div>');
  } else {
    $(this.mapId).append('<div class="mapField mapLabel"> </div>');
    for( var i=7;i>=0;i-- ) {
      $(this.mapId).append('<div class="mapField mapLabel">' + this.NamesAZ[i] + '</div>');
    }
    $(this.mapId).append('<div class="mapField mapLabel"> </div>');
  }

  if( game.activeUser != null ) {
    game.log("<b>" + game.activeUser + "'s turn</b>");
  }
  
  $('.mapField').on('click',function(){
    if( game.currentSelection != this && game.currentSelection == null ) {
      game.currentSelection = this;
      $(this).addClass('fieldActive');
    } else if( game.currentSelection == this ) {
      game.currentSelection = null;
      $('.mapField').removeClass('fieldActive');
    } else if( game.currentSelection != this && game.currentSelection != null ) {
      var from = $( game.currentSelection ).attr('id').replace(/field_/, '');
      var to = $( this ).attr('id').replace(/field_/, '');

      game.turn( from, to );

      game.currentSelection = null;
    }
  });
}

// ---------------------------------------------------------------------------
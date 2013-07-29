<h1 class="text-center">Chess - Game of <strike>Kings</strike> Nerds</h1>

<p></p>

<div class="row">
  <div id="gameArea" class="text-center">
    <div id="map"></div>
  </div>
  <div id="infoArea" class="text-center">
    <div id="ownUser" style="display: block"></div>
    <pre id="gameLogHolder" style="display: inline-block"><div id="gameLog"></div></pre>

    <form action="#" id="chatForm">
      <input type="text" id="MsgSendField" placeholder="Enter your message ...">
      <button id="MsgSendButton" type="submit" class="btn btn-primary">
        <i class="icon-comment icon-white"></i> Send
      </button>
    </form>

    <form action="#" id="networkForm">
      <input type="text" id="host" value="ws://127.0.0.1:8000/chess"> <!-- 192.168.105.41 -->

      <button id="connect" type="button" class="btn btn-action">
        <i class="icon-refresh icon-black"></i> Connect
      </button>

      <button id="disconnect" type="button" class="btn btn-action">
        <i class="icon-off icon-black"></i> Disconnect
      </button>

      <button id="showLog" type="button" class="btn btn-action">
        <i class="icon-list icon-black"></i> Log
      </button>
    </form>
  </div>
</div>

<p>&nbsp;</p>


<pre id="log"></pre>

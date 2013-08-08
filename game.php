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
      <select id="host">
        <option value="">[choose server]</option>
        <option value="ws://xardaska-network-system.net:8000/chess">xardaska-network-system.net</option>
        <option value="ws://127.0.0.1:8000/chess">localhost</option>
      </select>

      <button id="connect" type="button" class="btn btn-action">
        <i class="icon-refresh icon-black"></i> <span>Connect</span>
      </button>
    </form>
  </div>
</div>

<audio id="turnAudio" src="audio/turn.wav" preload="auto"></audio>
<audio id="chatAudio" src="audio/chat.wav" preload="auto"></audio>

<p>&nbsp;</p>


<pre id="log"></pre>

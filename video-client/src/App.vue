<script setup>
import { onMounted, ref } from 'vue';

const socket = ref(null);
const localVideo = ref(null)
const remoteVideo = ref(null)

const isCameraOn = ref(true)
const isMicOn = ref(true)
const roomId = ref(null);

const toggleCamera = () => {
  const stream = localVideo.value.srcObject
  if (stream) {
    const videoTrack = stream.getVideoTracks()[0]
    if (videoTrack) {
      videoTrack.enabled = !videoTrack.enabled
      isCameraOn.value = videoTrack.enabled
    }
  }
}

const toggleMic = () => {
  const stream = localVideo.value.srcObject
  if (stream) {
    const audioTrack = stream.getAudioTracks()[0]
    if (audioTrack) {
      audioTrack.enabled = !audioTrack.enabled
      isMicOn.value = audioTrack.enabled
    }
  }
}

let peerConnection = null;

const configuration = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' }
    ]
}

const createPeerConnection = () => {
  peerConnection = new RTCPeerConnection(configuration);

  peerConnection.onicecandidate = (event) => {
      if (event.candidate) {
          socket.value.send(JSON.stringify({ type: 'ice-candidate', candidate: event.candidate }));
      }
  };

  peerConnection.ontrack = (event) => {
      if (remoteVideo.value) {
          remoteVideo.value.srcObject = event.streams[0];
      }
  };

  const stream = localVideo.value.srcObject
  stream.getTracks().forEach(track => {
      peerConnection.addTrack(track, stream)
  })
}

const connectWebSocket = (currentRoomId) => {
    socket.value = new WebSocket(`ws://localhost:8080?room=${currentRoomId}`);

    socket.value.onopen = () => {
        console.log(`WebSocket connection established for room: ${currentRoomId}`);
        socket.value.send(JSON.stringify({ type: 'join-room', roomId: currentRoomId }));
    };

    socket.value.onmessage = (event) => {
        handleMessage(event)
    };

    socket.value.onerror = (error)=> {
        console.error("WebSocket error: ", error);
    };

    socket.value.onclose = () => {
        console.log("WebSocket connection closed");
    };
}

const joinRoom = (id) => {
    roomId.value = id;
    connectWebSocket(id);
};

const createRoom = async () => {
  try {
    const response = await fetch('http://localhost:8000/api/create-room.php');
    const data = await response.json();
    roomId.value = data.roomId;
    history.pushState({ roomId: data.roomId }, '', `?room=${data.roomId}`);
    joinRoom(data.roomId);
  } catch (error) {
    console.error('Error creating room:', error);
  }
};


onMounted(async () => {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    if (localVideo.value) {
      localVideo.value.srcObject = stream;
    }
  } catch (error){
    console.error('Error here', error)
  }

  const urlParams = new URLSearchParams(window.location.search);
  const existingRoomId = urlParams.get('room');

  if (existingRoomId) {
    joinRoom(existingRoomId);
  }
});

const sendMessage = (message) => {
  socket.value.send(JSON.stringify(message))
}

const startCall = async () => {
  createPeerConnection()
  
  const offer = await peerConnection.createOffer()
  await peerConnection.setLocalDescription(offer)
  
  sendMessage({
    type: 'offer',
    offer: offer
  })
}

const handleMessage = async(event) => {
  const message = JSON.parse(event.data)

  console.log("Handling message: ", message)
  if (message.type === 'offer') {
    await createPeerConnection()

    await peerConnection.setRemoteDescription(new RTCSessionDescription(message.offer))

    const answer = await peerConnection.createAnswer()
    await peerConnection.setLocalDescription(answer)
    sendMessage({
      type: 'answer',
      answer: answer
    })
  } else if (message.type === 'answer') {
    await peerConnection.setRemoteDescription(new RTCSessionDescription(message.answer))
  } else if (message.type === 'ice-candidate') {
        if (peerConnection) {
            await peerConnection.addIceCandidate(new RTCIceCandidate(message.candidate))
        }
    }
}
</script>

<template>
  <h1>Video Call App</h1>

 <div class="video-grid" style="display: flex; gap: 20px;">
    <div class="video-wrapper">
      <h3>You</h3>
      <video ref="localVideo" autoplay playsinline muted style="width: 300px; border: 2px solid blue; transform: scaleX(-1);"></video>
    </div>

    <div class="video-wrapper">
      <h3>Remote</h3>
      <video ref="remoteVideo" autoplay playsinline style="width: 300px; border: 2px solid red;"></video>
    </div>
  </div>

  <div class="controls" style="margin-top: 20px; display: flex; gap: 10px;">
      <button v-if="!roomId" @click="createRoom" style="padding: 10px 20px; font-size: 16px;">
        ➕ Create Room
      </button>
      <button v-else @click="startCall" style="padding: 10px 20px; font-size: 16px;">
        📞 Start Call in Room {{ roomId }}
      </button>
      <button @click="toggleCamera" style="padding: 10px 20px; font-size: 16px;">
        {{ isCameraOn ? '📷 Turn Camera Off' : '📷 Turn Camera On' }}
      </button>
      <button @click="toggleMic" style="padding: 10px 20px; font-size: 16px;">
        {{ isMicOn ? '🎤 Turn Mic Off' : '🎤 Turn Mic On' }}
      </button>
  </div>
</template>

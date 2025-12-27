<script setup>
import { onMounted, onUnmounted, ref, defineProps, defineEmits } from 'vue';

const props = defineProps({
  roomId: {
    type: String,
    required: true
  }
});

const emit = defineEmits(['leave-room']);

const socket = ref(null);
const myId = ref(null);
const localVideo = ref(null)
const audioCanvas = ref(null)

const isCameraOn = ref(true)
const isMicOn = ref(true)

const peers = {};
const remoteStreams = ref({});

// Audio Visualizer Variables
let audioContext = null;
let analyser = null;
let dataArray = null;
let animationId = null;
let audioSource = null;

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

const leaveRoom = () => {
  Object.values(peers).forEach(pc => pc.close());
  for (const key in peers) delete peers[key];
  remoteStreams.value = {};

  if (socket.value) {
    socket.value.close();
  }

  if (localVideo.value && localVideo.value.srcObject) {
    localVideo.value.srcObject.getTracks().forEach(track => track.stop());
  }

  window.history.pushState({}, '', window.location.pathname);
  emit('leave-room');
}

const setupAudioVisualizer = (stream) => {
  if (!audioCanvas.value) return;

  if (!audioContext) {
      audioContext = new (window.AudioContext || window.webkitAudioContext)();
  }
  
  if (audioSource) {
      audioSource.disconnect();
  }

  analyser = audioContext.createAnalyser();
  analyser.fftSize = 32;
  
  const bufferLength = analyser.frequencyBinCount; 
  dataArray = new Uint8Array(bufferLength);

  audioSource = audioContext.createMediaStreamSource(stream);
  audioSource.connect(analyser);

  drawVisualizer();
};

const drawVisualizer = () => {
  if (!audioCanvas.value || !analyser || !dataArray) return;
  
  const canvas = audioCanvas.value;
  const ctx = canvas.getContext('2d');
  const width = canvas.width;
  const height = canvas.height;

  animationId = requestAnimationFrame(drawVisualizer);

  ctx.clearRect(0, 0, width, height);

  if (!isMicOn.value) {
    ctx.fillStyle = 'rgba(255, 85, 0, 0.3)';
    ctx.fillRect(0, height / 2 - 1, width, 2);
    return;
  }

  analyser.getByteFrequencyData(dataArray);

  const numberOfBars = 5;
  const barGap = 2;
  const totalBarWidth = (width - (numberOfBars - 1) * barGap);
  const singleBarWidth = totalBarWidth / numberOfBars;
  
  let x = 0;

  for (let i = 0; i < numberOfBars; i++) {
    const dataIndex = Math.floor(i * (dataArray.length / numberOfBars));
    let barValue = dataArray[dataIndex] || 0;
    const barHeight = (barValue / 255) * height * 0.8;

    ctx.fillStyle = `#FF5500`; 
    ctx.fillRect(x, height - barHeight, singleBarWidth, barHeight);

    x += singleBarWidth + barGap;
  }
};

const configuration = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' }
    ]
}

const createPeerConnection = (targetUserId, initiator) => {
    if (peers[targetUserId]) return peers[targetUserId];

    const pc = new RTCPeerConnection(configuration);
    peers[targetUserId] = pc;

    pc.onicecandidate = (event) => {
        if (event.candidate) {
            sendMessage({
                target: targetUserId,
                type: 'ice-candidate',
                candidate: event.candidate
            });
        }
    };

    pc.ontrack = (event) => {
        console.log(`Received track from ${targetUserId}`, event.streams[0]);
        remoteStreams.value = {
            ...remoteStreams.value,
            [targetUserId]: event.streams[0]
        };
    };

    const stream = localVideo.value.srcObject;
    if (stream) {
        stream.getTracks().forEach(track => {
            pc.addTrack(track, stream);
        });
    }

    return pc;
}

const connectWebSocket = (currentRoomId) => {
    const wsUrl = import.meta.env.VITE_WS_URL;
    if (!wsUrl) {
        console.error('WebSocket URL is missing. Please set VITE_WS_URL.');
        return;
    }
    socket.value = new WebSocket(`${wsUrl}?room=${currentRoomId}`);

    socket.value.onopen = () => {
        console.log(`WebSocket connection established for room: ${currentRoomId}`);
    };

    socket.value.onmessage = (event) => {
        handleMessage(event);
    };

    socket.value.onerror = (error)=> {
        console.error("WebSocket error: ", error);
    };

    socket.value.onclose = () => {
        console.log("WebSocket connection closed");
    };
}

const sendMessage = (message) => {
  if (socket.value && socket.value.readyState === WebSocket.OPEN) {
      socket.value.send(JSON.stringify(message));
  }
}

const handleMessage = async (event) => {
    const message = JSON.parse(event.data);

    if (message.type === 'me') {
        myId.value = message.id;
        console.log('My ID:', myId.value);
    } else if (message.type === 'user-connected') {
        const userId = message.userId;
        console.log('User connected:', userId);
        
        const pc = createPeerConnection(userId, true);
        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        
        sendMessage({
            target: userId,
            type: 'offer',
            offer: offer
        });

    } else if (message.type === 'user-disconnected') {
        const userId = message.userId;
        console.log('User disconnected:', userId);
        if (peers[userId]) {
            peers[userId].close();
            delete peers[userId];
        }
        const newStreams = { ...remoteStreams.value };
        delete newStreams[userId];
        remoteStreams.value = newStreams;

    } else if (message.type === 'signal') {
        const senderId = message.sender;
        const data = message.data;

        if (data.target && data.target !== myId.value) {
            return;
        }

        if (data.type === 'offer') {
            const pc = createPeerConnection(senderId, false);
            await pc.setRemoteDescription(new RTCSessionDescription(data.offer));
            
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            
            sendMessage({
                target: senderId,
                type: 'answer',
                answer: answer
            });

        } else if (data.type === 'answer') {
            const pc = peers[senderId];
            if (pc) {
                await pc.setRemoteDescription(new RTCSessionDescription(data.answer));
            }

        } else if (data.type === 'ice-candidate') {
            const pc = peers[senderId];
            if (pc) {
                await pc.addIceCandidate(new RTCIceCandidate(data.candidate));
            }
        }
    }
}

onMounted(async () => {
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
    if (localVideo.value) {
      localVideo.value.srcObject = stream;
      setupAudioVisualizer(stream);
    }
  } catch (error){
    console.error('Error getting user media', error);
    alert("Could not access camera/microphone.");
  }

  if (props.roomId) {
    connectWebSocket(props.roomId);
  }
});

onUnmounted(() => {
  if (animationId) cancelAnimationFrame(animationId);
  if (audioContext) audioContext.close();
  leaveRoom();
});
</script>

<template>
  <div class="video-room">
    <div class="room-header">
      <button @click="leaveRoom" class="back-button">← Leave Room</button>
      <h2>Room: {{ roomId }}</h2>
      <div class="spacer"></div>
    </div>

    <div class="video-grid" :class="{'one-user': Object.keys(remoteStreams).length === 0, 'two-users': Object.keys(remoteStreams).length === 1}">
        <div class="video-wrapper">
            <h3>You</h3>
            <div class="video-container">
                <video ref="localVideo" autoplay playsinline muted class="local-video"></video>
            </div>
        </div>

        <div v-for="(stream, userId) in remoteStreams" :key="userId" class="video-wrapper">
            <h3>User {{ userId.substr(0, 4) }}</h3>
            <div class="video-container">
                <video :srcObject="stream" autoplay playsinline class="remote-video"></video>
            </div>
        </div>
    </div>

    <div class="controls">
        <div class="control-group">
             <button @click="toggleCamera" :class="{'btn-off': !isCameraOn}">
                {{ isCameraOn ? '📷 Camera On' : '📷 Camera Off' }}
            </button>
            <button @click="toggleMic" :class="{'mic-toggle-on': isMicOn, 'mic-toggle-off': !isMicOn}" class="mic-button-with-viz">
                <span class="mic-label">{{ isMicOn ? '🎤 Mic On' : '🎤 Mic Off' }}</span>
                <canvas ref="audioCanvas" width="40" height="20" class="mic-visualizer-inline"></canvas>
            </button>
        </div>
    </div>
  </div>
</template>

<style scoped>
.video-room {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-height: 100vh;
    color: white;
    background: #000;
}

.room-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 1rem 2rem;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(10px);
    z-index: 100;
}

.room-header h2 {
    margin: 0;
    flex: 1;
    text-align: center;
    font-size: 1.2rem;
    color: #ccc;
}

.spacer {
    width: 140px;
}

.back-button {
    background: transparent;
    border: 2px solid #FF5500;
    color: #FF5500;
    padding: 8px 16px;
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s;
}

.back-button:hover {
    background: #FF5500;
    color: white;
    transform: translateX(-2px);
}

.video-grid {
    display: grid;
    gap: 20px;
    width: 100%;
    flex: 1;
    padding: 20px;
    align-content: center;
    max-width: 1600px;
    margin: 0 auto;
}

/* Default: Grid for 3+ users */
.video-grid {
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
}

/* 1 User (Just me) - Full Screenish */
.video-grid.one-user {
    grid-template-columns: 1fr;
    max-width: 1000px;
}

/* 2 Users - Split Screen */
.video-grid.two-users {
    grid-template-columns: 1fr 1fr;
}

@media (max-width: 768px) {
    .video-grid.two-users {
        grid-template-columns: 1fr;
    }
}

.video-wrapper {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    width: 100%;
    height: 100%;
    justify-content: center;
}

.video-wrapper h3 {
    position: absolute;
    top: 15px;
    left: 15px;
    background: rgba(0, 0, 0, 0.6);
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 14px;
    z-index: 10;
    margin: 0;
    backdrop-filter: blur(4px);
}

.video-container {
    position: relative;
    width: 100%;
    aspect-ratio: 16/9;
    background: #111;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
}

.local-video, .remote-video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.local-video {
    transform: scaleX(-1);
}

.controls {
    display: flex;
    gap: 15px;
    padding: 20px;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(10px);
    width: 100%;
    justify-content: center;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 100;
}

.control-group {
    display: flex;
    gap: 15px;
    background: rgba(255, 255, 255, 0.1);
    padding: 10px;
    border-radius: 50px;
}

button {
    padding: 12px 24px;
    font-size: 16px;
    color: white;
    border-radius: 50px;
    cursor: pointer;
    transition: all 0.3s;
    background-color: #333;
    border: 1px solid rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    gap: 8px;
}

button:hover {
    background-color: #444;
}

.btn-off {
    background-color: #cc0000 !important;
    border-color: #ff0000 !important;
}

.mic-button-with-viz {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 20px;
    min-width: 140px;
}

.mic-toggle-on {
    background-color: #333;
    border: 1px solid #FF5500;
    color: #FF5500;
}

.mic-toggle-on:hover {
    background-color: #2a1a10;
}

.mic-toggle-off {
    background-color: #cc0000;
    border: 1px solid #ff0000;
    color: white;
}

.mic-visualizer-inline {
    width: 40px;
    height: 20px;
    background: rgba(0, 0, 0, 0.5);
    border-radius: 4px;
}
</style>

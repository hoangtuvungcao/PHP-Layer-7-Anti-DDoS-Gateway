<?php
#require_once 'loading.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cyber Ocean - VanTrong</title>

  <!-- jQuery -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

  <!-- CSS -->
  <style>
    html, body {
      margin: 0;
      padding: 0;
      overflow: hidden;
      height: 100%;
      background: radial-gradient(circle at 50% 50%, #070720 0%, #000010 100%);
      font-family: "Orbitron", sans-serif;
      color: #00ffff;
    }

    #jsi-flying-fish-container {
      position: fixed;
      inset: 0;
      overflow: hidden;
      background: radial-gradient(circle at 50% 50%, #0a0a2a 0%, #01010a 100%);
      animation: bgPulse 10s infinite alternate;
    }

    @keyframes bgPulse {
      0% { filter: brightness(1) hue-rotate(0deg); }
      100% { filter: brightness(1.3) hue-rotate(40deg); }
    }

    /* Logo / text hologram in center */
    .center-logo {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      color: #00eaff;
      font-size: 3rem;
      letter-spacing: 2px;
      text-shadow:
        0 0 10px #00eaff,
        0 0 20px #00eaff,
        0 0 40px #7d5fff,
        0 0 80px #7d5fff;
      animation: float 4s ease-in-out infinite;
      user-select: none;
    }

    @keyframes float {
      0%, 100% { transform: translate(-50%, -50%) translateY(0); }
      50% { transform: translate(-50%, -50%) translateY(-15px); }
    }
  </style>
</head>

<body>
  <div id="jsi-flying-fish-container"></div>
  <div class="center-logo">VAN TRỌNG</div>

<!-- Flying Fish JS -->
<script>
/* === Neon Flying Fish Code === */
var RENDERER = {
  POINT_INTERVAL: 5,
  FISH_COUNT: 3,
  MAX_INTERVAL_COUNT: 50,
  INIT_HEIGHT_RATE: 0.5,
  THRESHOLD: 50,
  init: function () {
    this.setParameters();
    this.reconstructMethods();
    this.setup();
    this.bindEvent();
    this.render();
  },
  setParameters: function () {
    this.$window = $(window);
    this.$container = $("#jsi-flying-fish-container");
    this.$canvas = $("<canvas />");
    this.context = this.$canvas.appendTo(this.$container).get(0).getContext("2d");
    this.points = [];
    this.fishes = [];
  },
  createSurfacePoints: function () {
    var count = Math.round(this.width / this.POINT_INTERVAL);
    this.pointInterval = this.width / (count - 1);
    this.points.push(new SURFACE_POINT(this, 0));
    for (var i = 1; i < count; i++) {
      var p = new SURFACE_POINT(this, i * this.pointInterval), prev = this.points[i - 1];
      p.setPreviousPoint(prev);
      prev.setNextPoint(p);
      this.points.push(p);
    }
  },
  reconstructMethods: function () {
    this.render = this.render.bind(this);
  },
  setup: function () {
    this.points = [];
    this.fishes = [];
    this.width = this.$container.width();
    this.height = this.$container.height();
    this.$canvas.attr({ width: this.width, height: this.height });
    for (let i = 0; i < this.FISH_COUNT; i++) {
      this.fishes.push(new FISH(this));
    }
    this.createSurfacePoints();
  },
  bindEvent: function () {
    this.$window.on("resize", () => this.setup());
  },
  controlStatus: function () {
    for (let p of this.points) p.updateSelf();
    for (let p of this.points) p.updateNeighbors();
  },
  render: function () {
    requestAnimationFrame(this.render);
    this.controlStatus();
    const ctx = this.context;
    let grad = ctx.createLinearGradient(0, 0, 0, this.height);
    grad.addColorStop(0, "#050518");
    grad.addColorStop(1, "#0c0c2e");
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, this.width, this.height);

    for (let f of this.fishes) f.render(ctx);

    ctx.save();
    ctx.globalCompositeOperation = "lighter";
    ctx.beginPath();
    ctx.moveTo(0, this.height);
    for (let p of this.points) p.render(ctx);
    ctx.lineTo(this.width, this.height);
    ctx.closePath();

    let water = ctx.createLinearGradient(0, 0, 0, this.height);
    water.addColorStop(0, "rgba(0,255,255,0.15)");
    water.addColorStop(1, "rgba(150,0,255,0.25)");
    ctx.fillStyle = water;
    ctx.shadowBlur = 25;
    ctx.shadowColor = "#7d5fff";
    ctx.fill();
    ctx.restore();
  }
};

function SURFACE_POINT(renderer, x) {
  this.renderer = renderer;
  this.x = x;
  this.init();
}
SURFACE_POINT.prototype = {
  SPRING_CONSTANT: 0.03,
  SPRING_FRICTION: 0.9,
  WAVE_SPREAD: 0.3,
  ACCELARATION_RATE: 0.01,
  init: function () {
    this.initHeight = this.renderer.height * this.renderer.INIT_HEIGHT_RATE;
    this.height = this.initHeight;
    this.fy = 0;
    this.force = { previous: 0, next: 0 };
  },
  setPreviousPoint: function (p) { this.previous = p; },
  setNextPoint: function (n) { this.next = n; },
  updateSelf: function () {
    this.fy += this.SPRING_CONSTANT * (this.initHeight - this.height);
    this.fy *= this.SPRING_FRICTION;
    this.height += this.fy;
  },
  updateNeighbors: function () {
    if (this.previous) this.force.previous = this.WAVE_SPREAD * (this.height - this.previous.height);
    if (this.next) this.force.next = this.WAVE_SPREAD * (this.height - this.next.height);
  },
  render: function (ctx) {
    if (this.previous) {
      this.previous.height += this.force.previous;
      this.previous.fy += this.force.previous;
    }
    if (this.next) {
      this.next.height += this.force.next;
      this.next.fy += this.force.next;
    }
    ctx.lineTo(this.x, this.renderer.height - this.height);
  }
};

function FISH(renderer) {
  this.renderer = renderer;
  this.init();
}
FISH.prototype = {
  init: function () {
    this.direction = Math.random() < 0.5;
    this.x = this.direction ? this.renderer.width + 50 : -50;
    this.vx = (Math.random() * 6 + 4) * (this.direction ? -1 : 1);
    this.y = Math.random() * this.renderer.height;
    this.vy = Math.random() * 2 - 1;
    this.ay = Math.random() * 0.2 - 0.1;
  },
  render: function (ctx) {
    this.x += this.vx;
    this.y += this.vy;
    this.vy += this.ay;
    if (this.x < -100 || this.x > this.renderer.width + 100) this.init();

    let grad = ctx.createLinearGradient(-30, -20, 40, 20);
    grad.addColorStop(0, "#00ffff");
    grad.addColorStop(1, "#7d5fff");
    ctx.fillStyle = grad;
    ctx.shadowBlur = 15;
    ctx.shadowColor = "#00eaff";

    ctx.save();
    ctx.translate(this.x, this.y);
    ctx.rotate(Math.PI + Math.atan2(this.vy, this.vx));
    ctx.beginPath();
    ctx.moveTo(-20, 0);
    ctx.bezierCurveTo(-15, 10, 10, 6, 25, 0);
    ctx.bezierCurveTo(10, -6, -15, -10, -20, 0);
    ctx.fill();
    ctx.restore();
  }
};

$(function () { RENDERER.init(); });

// === AUDIO ===
const bgAudio = document.getElementById('bgAudio');
const audioToggle = document.getElementById('audioToggle');
bgAudio.volume = 0.5;

// tự autoplay khi load
window.addEventListener('load', () => {
  bgAudio.play().then(() => {
    bgAudio.muted = false;
    audioToggle.setAttribute('aria-pressed', 'true');
    audioToggle.textContent = '🔊 Đang phát';
  }).catch(() => {
    bgAudio.muted = true;
    audioToggle.setAttribute('aria-pressed', 'false');
    audioToggle.textContent = '🔇Âm nhạc';
  });
});

// bật tiếng khi người dùng click lần đầu
document.addEventListener('click', () => {
  if (bgAudio.muted) {
    bgAudio.muted = false;
    bgAudio.play();
    audioToggle.setAttribute('aria-pressed', 'true');
    audioToggle.textContent = '🔊 Đang phát';
  }
}, { once: true });

// toggle nhạc
audioToggle.addEventListener('click', () => {
  if (bgAudio.paused) {
    bgAudio.play();
    bgAudio.muted = false;
    audioToggle.setAttribute('aria-pressed', 'true');
    audioToggle.textContent = '🔊 Đang phát';
  } else {
    bgAudio.pause();
    audioToggle.setAttribute('aria-pressed', 'false');
    audioToggle.textContent = '🔇Âm nhạc';
  }
});
</script>

<!-- AUDIO TAG -->
<audio id="bgAudio" src="./assets/audio/chay-khoi-the-gioi-nay.mp3" loop preload="auto" autoplay muted></audio>

<div id="jsi-flying-fish-container"></div>
<div class="center-logo">VAN TRỌNG</div>
<button class="audio-toggle" id="audioToggle" type="button" aria-pressed="false">🔇Âm nhạc</button>
<audio id="bgAudio" src="./assets/audio/chay-khoi-the-gioi-nay.mp3" loop preload="auto"></audio>

</body>
</html>

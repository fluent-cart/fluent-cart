<template>
  <div class="fct-animated-icon-wrap">
    <div class="fct-animated-cart">
      <div class="fct-overlay">
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
      </div>
      <div class="fct-overlay fct-overlay2">
        <span></span>
        <span></span>
        <span></span>
        <span></span>
        <span></span>
      </div>
      <svg class="fct-cart-loader" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 281 183" fill="none">
        <g>
          <path d="M66.551 150.779C73.0358 150.779 78.301 156.044 78.301 162.529C78.301 169.014 73.0358 174.279 66.551 174.279C60.0662 174.279 54.801 169.014 54.801 162.529C54.801 156.044 60.0662 150.779 66.551 150.779Z" stroke="#383434" stroke-width="6"/>
          <path d="M154.551 150.779C161.036 150.779 166.301 156.044 166.301 162.529C166.301 169.014 161.036 174.279 154.551 174.279C148.066 174.279 142.801 169.014 142.801 162.529C142.801 156.044 148.066 150.779 154.551 150.779Z" stroke="#383434" stroke-width="6"/>
          <g class="fct-cart-loader-main">
            <path d="M3.60199 3.12674L25.602 13.1267L50.602 117.627L58.602 130.127L171.102 129.627" stroke="#383434" stroke-width="6" stroke-linecap="round"/>
            <path d="M30.602 32.8326L188.602 43.2444L170.102 116.127L49.602 114.626" stroke="#383434" stroke-width="6.45345"/>
            <path d="M71.352 35.8767L79.102 115.127" stroke="#383434" stroke-width="6"/>
            <path d="M150.102 40.1267L141.102 114.627" stroke="#383434" stroke-width="6"/>
            <path d="M37.102 60.8767L183.852 66.1267" stroke="#383434" stroke-width="6"/>
            <path d="M44.602 88.1267L176.602 91.1267" stroke="#383434" stroke-width="6"/>
          </g>
          <path class="fct-cart-line-anim" d="M120.917 122.5C193.404 122.5 263.195 122.5 277.738 122.5" stroke="#898989" stroke-width="5.16658" stroke-linecap="round"/>
          <path class="fct-cart-line-anim fct-cart-line-anim2" d="M90.917 72.5C163.404 72.5 233.195 72.5 247.738 72.5" stroke="#383434" stroke-width="5.16658" stroke-linecap="round"/>
        </g>
        <path class="hidden" d="M16.5 180.25L204 180" stroke="#3C3C3C" stroke-width="5" stroke-linecap="round"/>
      </svg>
    </div>
  </div>
</template>


<style scoped lang="scss">
.fct-animated-icon-wrap {
  position: fixed;
  width: calc(100% - 160px);
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9;
  left: 160px;
  top: 0;
  padding: 50px;
  box-sizing: border-box;
  background: rgba(255,255,255,0.7);
  * {
    box-sizing: border-box;
  }

  .fct-animated-cart {
    width: 100px;
    min-height: 65px;
    overflow: hidden;
    position: relative;
    display: flex;
    justify-content: center;
    transition: 2s;
    &:before {
      content: '';
      height: 2px;
      bottom: 0px;
      width: 100%;
      position: absolute;
      background: #3C3C3C;
    }
    .fct-overlay {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 100px;
      position: absolute;
      left: 100%;
      top: 8%;
      width: 100%;
      opacity: 0;
      visibility: hidden;
      animation: overlayAnim 1s linear infinite;

      span {
        display: block;
        width: 100%;
        height: 1px;
        background: #ccc;
      }
    }
    .fct-overlay2 {
      top: 55%;
      animation: overlayAnim 1.5s linear infinite;
      span {
        background: #d4d4d4;
      }
    }
  }

  .fct-cart-loader {
    width: 100px;
    @apply absolute left-0 bottom-0;
    .fct-cart-loader-main {
      animation: cartIconSpin 0.5s linear infinite;
      transform-box: fill-box; /* IMPORTANT */
      transform-origin: center; /* OPTIONAL but helps in many cases */
      z-index: 4;
    }
    .fct-cart-line-anim {
      animation: cartLineAnim 1s linear infinite;
      transform-box: fill-box; /* IMPORTANT */
      transform-origin: center; /* OPTIONAL but helps in many cases */
      z-index: -1;
    }
    .fct-cart-line-anim2 {
      animation: cartLineAnim 0.8s linear infinite;
    }
  }
  &.finish {
    .fct-overlay {
      opacity: 1;
      visibility: visible;
    }
    .fct-animated-cart {
      max-width: 50%;
      width: 50%;
    }
    .fct-cart-loader-main {
      animation-duration: .2s;
    }
    .fct-cart-line-anim2 {
      opacity: 0;
      animation-duration: .3s;
    }
    .fct-cart-line-anim  {
      opacity: 0;
      animation-duration: .7s;
    }
    .fct-cart-loader {
      animation: cartLoaderAnim 1.8s linear forwards;
    }
  }
}

@keyframes cartIconSpin {
  0%, 100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(6px);
  }
}
@keyframes cartLineAnim {
  0% {
    transform: translateX(163px);
  }
  70%,
  80%,
  100% {
    transform: translateX(-200%);
  }
}

@keyframes cartLoaderAnim {
  0% {
    left: 0;
    bottom: 7px;
    transform: rotate(-15deg);
  }
  40% {
    bottom: 0;
    transform: rotate(0);
  }
  100% {
    left: 100%;
  }
}
@keyframes overlayAnim {
  0% {
    left: 100%;
  }
  100% {
    left: -100%;
  }
}


</style>

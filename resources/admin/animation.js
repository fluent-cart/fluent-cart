export const animateDirective = {
    mounted(el, binding) {
      const targetValue = binding.value;
      const duration = 1000; // Animation duration in milliseconds
      const startValue = 0; // Start value for the animation
      const startTime = performance.now();
  
      const update = () => {
        const elapsed = performance.now() - startTime;
        const progress = Math.min(elapsed / duration, 1); // Progress between 0 and 1
  
        const currentValue = (startValue + progress * (targetValue - startValue)).toFixed(2); // Rounded to 2 decimal places
        el.textContent = currentValue; // Update the DOM directly
  
        if (progress < 1) {
          requestAnimationFrame(update); // Continue animation
        }
      };
  
      update(); // Start the animation
    },
    updated(el, binding) {
      const targetValue = binding.value;
      const duration = 2000;
      const startValue = 0;
      const startTime = performance.now();
  
      const update = () => {
        const elapsed = performance.now() - startTime;
        const progress = Math.min(elapsed / duration, 1);
  
        const currentValue = (startValue + progress * (targetValue - startValue)).toFixed(2); // Rounded to 2 decimal places
        el.textContent = currentValue;
  
        if (progress < 1) {
          requestAnimationFrame(update);
        }
      };
  
      update();
    },
  };
  
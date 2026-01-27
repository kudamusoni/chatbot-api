<!doctype html>
<html>
  <body>
    <h3>SSE Test</h3>
    <pre id="log"></pre>

    <script>
      const log = (m) => {
        document.getElementById("log").textContent += m + "\n";
        console.log(m);
      };

      const clientId = "019bfc67-b41b-704b-9c95-b9413be1d21c";
      const token = "5meQ1rrG4hXSxOVdrAHywlev0yP6PteWGlqJLBVwxPWHAcuorTh2ibRxxW8zCf1R";

      const es = new EventSource(
        `http://localhost/api/widget/sse?client_id=${clientId}&session_token=${token}&after_id=0`
      );

      es.onopen = () => log("✅ SSE connected");

      es.addEventListener("conversation.event", (e) => {
        log("📨 conversation.event: " + e.data);
      });

      es.addEventListener("conversation.error", (e) => {
        log("🛑 conversation.error: " + e.data);
      });

      es.onerror = (e) => log("❌ SSE error");
    </script>
  </body>
</html>

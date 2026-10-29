<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Continuing sign in</title>
</head>
<body>
    <form id="hosted-transition" method="post" action="{{ route('hosted.store') }}">
        @csrf
        <input type="hidden" name="flow" value="{{ $flow }}">
        <input type="hidden" name="screen" value="{{ $screen }}">
        <input type="hidden" name="hosted" value="1">
        <button type="submit">Continue</button>
    </form>
    <p id="hosted-expired" hidden>Your sign-in has expired. Start again from the application.</p>
    <script>
        (() => {
            const form = document.getElementById('hosted-transition');
            const flow = form.elements.namedItem('flow');
            const screen = form.elements.namedItem('screen');
            const restore = @json($restore);
            try {
                if (restore) {
                    const saved = JSON.parse(sessionStorage.getItem('koamishin.hosted') || 'null');
                    if (!saved || typeof saved.flow !== 'string' || typeof saved.screen !== 'string') {
                        form.hidden = true;
                        document.getElementById('hosted-expired').hidden = false;
                        return;
                    }
                    flow.value = saved.flow;
                    screen.value = saved.screen;
                } else {
                    sessionStorage.setItem('koamishin.hosted', JSON.stringify({flow: flow.value, screen: screen.value}));
                }
            } catch (_) {
                if (restore) {
                    form.hidden = true;
                    document.getElementById('hosted-expired').hidden = false;
                    return;
                }
            }
            form.submit();
        })();
    </script>
</body>
</html>

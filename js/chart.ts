<script>
document.addEventListener('DOMContentLoaded', () => {

    /* SAMPLE COUNTERS */
    document.getElementById('totalRecords').textContent = 1245;
    document.getElementById('pendingRequests').textContent = 8;

    /* STUDENTS PER SCHOOL YEAR */
    new Chart(document.getElementById('studentsChart'), {
        type: 'pie',
        data: {
            labels: ['2022–2023', '2023–2024', '2024–2025'],
            datasets: [{
                data: [420, 465, 510],
                backgroundColor: ['#2563eb', '#22c55e', '#f59e0b']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    /* PERSONNEL DISTRIBUTION */
    new Chart(document.getElementById('personnelChart'), {
        type: 'pie',
        data: {
            labels: ['Teacher', 'Registrar/Admin'],
            datasets: [{
                data: [38, 22],
                backgroundColor: ['#0ea5e9', '#ef4444']
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

});
</script>

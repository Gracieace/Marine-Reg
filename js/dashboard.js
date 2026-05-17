document.addEventListener("DOMContentLoaded", () => {
    new Chart(document.getElementById('studentsChart'), {
        type: 'pie',
        data: {
            labels: schoolYears,
            datasets: [{
                data: studentsPerYear
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'center' } }
        }
    });

    new Chart(document.getElementById('personnelChart'), {
        type: 'pie',
        data: {
            labels: ['Teaching', 'Non-Teaching'],
            datasets: [{
                data: [teachers, nonTeachers]
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } }
        }
    });
});

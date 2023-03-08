const ratingInputs = document.querySelectorAll('.stars input[type="radio"]');
const ratingLabels = document.querySelectorAll('.stars label');

for (let i = 0; i < ratingInputs.length; i++) {
    ratingLabels[i].addEventListener('click', () => {
        for (let j = 0; j < ratingLabels.length; j++) {
            ratingLabels[j].classList.remove('checked');
        }
        for (let j = 0; j < i + 1; j++) {
            ratingLabels[j].classList.add('checked');
        }
    });
}
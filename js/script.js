const modal = document.getElementById("applicantModal");

const openBtn = document.getElementById("openModal");


if(openBtn){

    openBtn.onclick = function(){

        modal.style.display = "block";

    }

}


window.onclick = function(event){

    if(event.target == modal){

        modal.style.display = "none";

    }

}
// LOAN CALCULATOR


const amount =
document.getElementById("loan_amount");


const rate =
document.getElementById("interest_rate");


const duration =
document.getElementById("duration");



function calculateLoan(){


if(amount && rate && duration){


let loanAmount =
Number(amount.value);


let interestRate =
Number(rate.value);



let months =
Number(duration.value);



let interest =
loanAmount * (interestRate / 100);



// Starting balance; future interest is accrued server-side from the
// outstanding principal and must not be precomputed here.
let total = loanAmount;



let monthly =
(loanAmount / months) + interest;



document.getElementById(
"interest_amount"
).innerHTML =
interest.toFixed(2);



document.getElementById(
"total_repayment"
).innerHTML =
total.toFixed(2);



document.getElementById(
"monthly_payment"
).innerHTML =
monthly.toFixed(2);



// send values to PHP


document.getElementById(
"total_repayment_input"
).value =
total;



document.getElementById(
"monthly_payment_input"
).value =
monthly;


}


}



if(amount){

amount.addEventListener(
"input",
calculateLoan
);

rate.addEventListener(
"input",
calculateLoan
);

duration.addEventListener(
"input",
calculateLoan
);

}

const menuBtn = document.getElementById("menuToggle");

const sidebar = document.querySelector(".sidebar");

const main = document.querySelector(".main-content");

menuBtn.addEventListener("click",function(){

    sidebar.classList.toggle("collapsed");

    main.classList.toggle("expanded");

});
